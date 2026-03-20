<?php
/**
 * Plugin installer — runs on activation, handles DB table creation and setup.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Installer {

	public static function activate(): void {
		if ( version_compare( PHP_VERSION, '8.3', '<' ) ) {
			deactivate_plugins( plugin_basename( PRESSOCAMPUS_PLUGIN_FILE ) );
			wp_die( esc_html__( 'Pressocampus requires PHP 8.3 or higher.', 'pressocampus' ) );
		}

		global $wp_version;
		if ( version_compare( $wp_version, '6.4', '<' ) ) {
			deactivate_plugins( plugin_basename( PRESSOCAMPUS_PLUGIN_FILE ) );
			wp_die( esc_html__( 'Pressocampus requires WordPress 6.4 or higher.', 'pressocampus' ) );
		}

		if ( ! extension_loaded( 'sodium' ) ) {
			update_option( 'pressocampus_sodium_missing', true );
		} else {
			delete_option( 'pressocampus_sodium_missing' );
		}

		add_role( 'pressocampus_agent', 'Pressocampus Agent', array() );

		if ( ! username_exists( 'pressocampus_service' ) ) {
			$user_id = wp_create_user(
				'pressocampus_service',
				wp_generate_password( 32, true, true ),
				'pressocampus_service@' . wp_parse_url( home_url(), PHP_URL_HOST )
			);
			if ( ! is_wp_error( $user_id ) ) {
				$user = new \WP_User( $user_id );
				$user->set_role( 'pressocampus_agent' );
				update_option( 'pressocampus_service_user_id', $user_id );
			}
		}

		if ( ! get_option( 'pressocampus_rsa_private_key' ) ) {
			$config      = array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			);
			$res         = openssl_pkey_new( $config );
			$private_key = '';
			if ( $res && openssl_pkey_export( $res, $private_key ) && $private_key !== '' ) {
				$public_key_details = openssl_pkey_get_details( $res );
				if ( is_array( $public_key_details ) && isset( $public_key_details['key'] ) ) {
					update_option( 'pressocampus_rsa_private_key', $private_key );
					update_option( 'pressocampus_rsa_public_key', $public_key_details['key'] );
				}
			} else {
						update_option(
							'pressocampus_migration_error',
							'Failed to generate or export RSA key pair. OAuth will not work until this is resolved. Check that the openssl extension is available.'
						);
			}
		}

		self::run_migrations();

		// Schedule once; avoids duplicates if activate() is somehow re-called.
		if ( ! wp_next_scheduled( 'pressocampus_check_token_expiry' ) ) {
			wp_schedule_event( time(), 'daily', 'pressocampus_check_token_expiry' );
		}
		if ( ! wp_next_scheduled( 'pressocampus_expire_memories' ) ) {
			wp_schedule_event( time(), 'hourly', 'pressocampus_expire_memories' );
		}
		if ( ! wp_next_scheduled( 'pressocampus_purge_audit_log' ) ) {
			wp_schedule_event( time(), 'weekly', 'pressocampus_purge_audit_log' );
		}

		set_transient( 'pressocampus_show_welcome', true, 30 );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'pressocampus_check_token_expiry' );
		wp_clear_scheduled_hook( 'pressocampus_expire_memories' );
		wp_clear_scheduled_hook( 'pressocampus_purge_audit_log' );
		flush_rewrite_rules();
	}

	public static function run_migrations(): void {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		try {
			$sql1 = "CREATE TABLE {$wpdb->prefix}pressocampus_resource_index (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				post_id bigint(20) unsigned NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				uri varchar(500) NOT NULL,
				content_hash varchar(32) NOT NULL DEFAULT '',
				excerpt text NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uri (uri(191)),
				KEY post_id (post_id),
				KEY user_id (user_id),
				KEY user_post (user_id, post_id),
				FULLTEXT KEY excerpt_ft (excerpt)
			) $charset;";

			$sql2 = "CREATE TABLE {$wpdb->prefix}pressocampus_audit_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				oauth_client_name varchar(200) NOT NULL DEFAULT '',
				action varchar(100) NOT NULL,
				memory_uri varchar(500) NOT NULL DEFAULT '',
				memory_name varchar(500) NOT NULL DEFAULT '',
				context text NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY created_at (created_at),
				KEY action (action),
				KEY oauth_client_name (oauth_client_name(100))
			) $charset;";

			$sql3 = "CREATE TABLE {$wpdb->prefix}pressocampus_oauth_clients (
				id varchar(100) NOT NULL,
				name varchar(200) NOT NULL DEFAULT '',
				secret varchar(200) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				scopes varchar(500) NOT NULL DEFAULT '',
				is_confidential tinyint(1) NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				last_used_at datetime DEFAULT NULL,
				PRIMARY KEY (id)
			) $charset;";

			$sql4 = "CREATE TABLE {$wpdb->prefix}pressocampus_oauth_tokens (
				id varchar(200) NOT NULL,
				type enum('access','refresh','auth_code') NOT NULL DEFAULT 'access',
				client_id varchar(100) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				scopes varchar(500) NOT NULL DEFAULT '',
				revoked tinyint(1) NOT NULL DEFAULT 0,
				expires_at datetime NOT NULL,
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY client_id (client_id),
				KEY user_id (user_id),
				KEY expires_at (expires_at)
			) $charset;";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql1 );
			dbDelta( $sql2 );
			dbDelta( $sql3 );
			dbDelta( $sql4 );

			// 1.0 → 1.1: FULLTEXT index on excerpt + composite (user_id, post_id) index.
			$current_db_version = get_option( 'pressocampus_db_version', '0' );
			if ( version_compare( (string) $current_db_version, '1.1', '<' ) ) {
				$resource_table = $wpdb->prefix . 'pressocampus_resource_index';

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$has_ft = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
						$resource_table,
						'excerpt_ft'
					)
				);
				if ( ! $has_ft ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE `{$resource_table}` ADD FULLTEXT KEY excerpt_ft (excerpt)" );
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$has_composite = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
						$resource_table,
						'user_post'
					)
				);
				if ( ! $has_composite ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE `{$resource_table}` ADD KEY user_post (user_id, post_id)" );
				}
			}

			// 1.1 → 1.2: Add action and oauth_client_name indexes to audit_log.
			if ( version_compare( (string) $current_db_version, '1.2', '<' ) ) {
				$audit_table = $wpdb->prefix . 'pressocampus_audit_log';

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$has_action_idx = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
						$audit_table,
						'action'
					)
				);
				if ( ! $has_action_idx ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE `{$audit_table}` ADD KEY action (action)" );
				}

				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$has_client_idx = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s',
						$audit_table,
						'oauth_client_name'
					)
				);
				if ( ! $has_client_idx ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( "ALTER TABLE `{$audit_table}` ADD KEY oauth_client_name (oauth_client_name(100))" );
				}
			}

			update_option( 'pressocampus_db_version', PRESSOCAMPUS_DB_VERSION );
			delete_option( 'pressocampus_migration_error' );
		} catch ( \Throwable $e ) {
			update_option( 'pressocampus_migration_error', $e->getMessage() );
		}
	}
}
