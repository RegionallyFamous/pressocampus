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
		// 1. Check sodium extension — store result in option
		if ( ! extension_loaded( 'sodium' ) ) {
			update_option( 'pressocampus_sodium_missing', true );
		} else {
			delete_option( 'pressocampus_sodium_missing' );
		}

		// 2. Create custom role
		add_role( 'pressocampus_agent', 'Pressocampus Agent', array() );

		// 3. Create pressocampus_service user (only if not exists)
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

		// 4. Generate RSA key pair (only if not already present)
		if ( ! get_option( 'pressocampus_rsa_private_key' ) ) {
			$config = array(
				'digest_alg'       => 'sha256',
				'private_key_bits' => 2048,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
			);
			$res    = openssl_pkey_new( $config );
			if ( $res ) {
				openssl_pkey_export( $res, $private_key );
				$public_key_details = openssl_pkey_get_details( $res );
				update_option( 'pressocampus_rsa_private_key', $private_key );
				update_option( 'pressocampus_rsa_public_key', $public_key_details['key'] );
			}
		}

		// 5. Create DB tables
		self::run_migrations();

		// 6. Set welcome transient + flush rewrite rules
		set_transient( 'pressocampus_show_welcome', true, 30 );
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
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
				excerpt text NOT NULL DEFAULT '',
				PRIMARY KEY (id),
				UNIQUE KEY uri (uri(191)),
				KEY post_id (post_id),
				KEY user_id (user_id)
			) $charset;";

			$sql2 = "CREATE TABLE {$wpdb->prefix}pressocampus_audit_log (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				oauth_client_name varchar(200) NOT NULL DEFAULT '',
				action varchar(100) NOT NULL,
				memory_uri varchar(500) NOT NULL DEFAULT '',
				memory_name varchar(500) NOT NULL DEFAULT '',
				context text NOT NULL DEFAULT '',
				created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY (id),
				KEY user_id (user_id),
				KEY created_at (created_at)
			) $charset;";

			$sql3 = "CREATE TABLE {$wpdb->prefix}pressocampus_oauth_clients (
				id varchar(100) NOT NULL,
				name varchar(200) NOT NULL DEFAULT '',
				secret varchar(200) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL DEFAULT '',
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

			update_option( 'pressocampus_db_version', PRESSOCAMPUS_DB_VERSION );
			delete_option( 'pressocampus_migration_error' );
		} catch ( \Throwable $e ) {
			update_option( 'pressocampus_migration_error', $e->getMessage() );
		}
	}
}
