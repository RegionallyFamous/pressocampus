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

		// Cron hooks
		add_action( 'pressocampus_check_token_expiry', array( $this->oauth_server, 'notify_expiring_tokens' ) );
		add_action( 'pressocampus_expire_memories', array( $this->cpt, 'expire_old_memories' ) );

		if ( ! wp_next_scheduled( 'pressocampus_check_token_expiry' ) ) {
			wp_schedule_event( time(), 'daily', 'pressocampus_check_token_expiry' );
		}
		if ( ! wp_next_scheduled( 'pressocampus_expire_memories' ) ) {
			wp_schedule_event( time(), 'hourly', 'pressocampus_expire_memories' );
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

	// -----------------------------------------------------------------------
	// Getters (used by tests and external code)
	// -----------------------------------------------------------------------

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
