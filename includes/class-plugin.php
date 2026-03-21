<?php
/**
 * Core plugin singleton — boots and wires every subsystem.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static ?Plugin $instance = null;

	private CPT $cpt;
	private Auth $auth;
	private OAuthServer $oauth_server;
	private MCPEndpoint $mcp_endpoint;
	private AuditLog $audit_log;
	private Discovery $discovery;
	private ResourceIndex $resource_index;
	private Cache $cache;
	private Soul $soul;
	private Onboarding $onboarding;
	private Settings $settings;

	private function __construct() {
		// Run DB migrations automatically when the schema version is behind.
		// This covers plugin updates done by file-copy (FTP/ZIP) without a
		// deactivate → reactivate cycle, which would otherwise skip the activation hook.
		if ( get_option( 'pressocampus_db_version' ) !== PRESSOCAMPUS_DB_VERSION ) {
			Installer::run_migrations();
		}

		// Auto-generate the RSA key pair if it is missing.  This covers sites
		// where activation completed but the key generation silently failed
		// (e.g. openssl was enabled later, or the option was accidentally deleted).
		Installer::maybe_generate_rsa_keys();

		$this->cache          = new Cache();
		$this->resource_index = new ResourceIndex();
		$this->soul           = new Soul( $this->resource_index );
		$this->audit_log      = new AuditLog();
		$this->cpt            = new CPT( $this->resource_index, $this->soul );
		$this->auth           = new Auth( $this->cache );
		$this->oauth_server   = new OAuthServer( $this->auth, $this->audit_log );
		$this->auth->set_oauth_server( $this->oauth_server );
		$this->mcp_endpoint = new MCPEndpoint(
			$this->auth,
			$this->cpt,
			$this->resource_index,
			$this->soul,
			$this->audit_log,
			$this->cache
		);
		$this->discovery    = new Discovery();
		$this->onboarding   = new Onboarding();

		if ( is_admin() ) {
			$this->settings = new Settings( $this->auth, $this->audit_log, $this->soul );
		}

		$this->init_hooks();
	}

	public static function get_instance(): self {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function init_hooks(): void {
		add_action( 'init', array( $this, 'register_post_status' ) );
		add_action( 'init', array( $this, 'register_brain_rewrite' ) );

		// Cron job handlers — events are scheduled once in Installer::activate().
		add_action( 'pressocampus_check_token_expiry', array( $this->oauth_server, 'notify_expiring_tokens' ) );
		add_action( 'pressocampus_expire_memories', array( $this->cpt, 'expire_old_memories' ) );
		add_action( 'pressocampus_send_soul_notice', array( $this->soul, 'send_update_notice' ) );

		// Async index rebuild — triggered by mark_dirty() via wp_schedule_single_event.
		add_action(
			'pressocampus_rebuild_index',
			function ( int $user_id ): void {
				$host = Auth::get_site_host();
				$this->resource_index->rebuild_if_dirty( $user_id, $host, $this->soul );
			}
		);

		// Weekly audit log purge.
		add_action(
			'pressocampus_purge_audit_log',
			function (): void {
				$settings = get_option( 'pressocampus_settings', array() );
				$days     = max( 1, (int) ( $settings['audit_log_retention_days'] ?? 90 ) );
				$this->audit_log->purge_old( $days );
			}
		);
	}

	/**
	 * Register the /brain pretty-URL rewrite.
	 *
	 * Maps yoursite.com/brain → REST route /pressocampus/v1/mcp
	 * so users share a clean URL instead of the full wp-json path.
	 * Rewrite rules are flushed on activation and whenever the plugin
	 * version changes (covers file-copy updates that skip the hook).
	 * Plain permalinks are auto-upgraded to /%postname%/ because the
	 * plugin cannot function without the rewrite engine.
	 */
	public function register_brain_rewrite(): void {
		// Register the rule FIRST — flush_rewrite_rules() below will compile
		// this into the DB.  Any flush that happens before this line (e.g. the
		// one in Installer::activate()) will NOT include the /brain rule.
		add_rewrite_rule(
			'^brain/?$',
			'index.php?rest_route=/pressocampus/v1/mcp',
			'top'
		);

		$needs_flush = false;
		$hard_flush  = false;

		// Auto-upgrade plain permalinks — nothing works without them.
		if ( get_option( 'permalink_structure', '' ) === '' ) {
			update_option( 'permalink_structure', '/%postname%/' );
			$needs_flush = true;
			$hard_flush  = true; // Force .htaccess rewrite on Apache.
		}

		// Version change (covers file-copy upgrades and same-version reactivation
		// after delete_option() in Installer::activate()).
		if ( get_option( 'pressocampus_plugin_version' ) !== PRESSOCAMPUS_VERSION ) {
			$needs_flush = true;
		}

		// Post-activation flush flag set by Installer::activate().
		// Using a transient instead of calling flush_rewrite_rules() directly
		// in the activation hook guarantees the /brain rule is already registered
		// when the flush compiles the rewrite table.
		if ( get_transient( 'pressocampus_needs_flush' ) ) {
			delete_transient( 'pressocampus_needs_flush' );
			$needs_flush = true;
		}

		if ( $needs_flush ) {
			flush_rewrite_rules( $hard_flush );
			update_option( 'pressocampus_plugin_version', PRESSOCAMPUS_VERSION );
		}
	}

	public function register_post_status(): void {
		register_post_status(
			'pressocampus_expired',
			array(
				'label'                     => _x( 'Expired', 'post status', 'pressocampus' ),
				'public'                    => false,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => false,
				'show_in_admin_status_list' => false,
				/* translators: %s: post count */
				'label_count'               => _n_noop(
					'Expired <span class="count">(%s)</span>',
					'Expired <span class="count">(%s)</span>',
					'pressocampus'
				),
			)
		);
	}

	public function get_cpt(): CPT {
		return $this->cpt; }
	public function get_auth(): Auth {
		return $this->auth; }
	public function get_soul(): Soul {
		return $this->soul; }
	public function get_resource_index(): ResourceIndex {
		return $this->resource_index; }
	public function get_mcp_endpoint(): MCPEndpoint {
		return $this->mcp_endpoint; }
	public function get_audit_log(): AuditLog {
		return $this->audit_log; }
	public function get_cache(): Cache {
		return $this->cache; }
	public function get_oauth_server(): OAuthServer {
		return $this->oauth_server; }
	public function get_discovery(): Discovery {
		return $this->discovery; }
	public function get_onboarding(): Onboarding {
		return $this->onboarding; }
}
