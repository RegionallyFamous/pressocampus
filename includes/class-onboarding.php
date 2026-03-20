<?php
/**
 * Onboarding — first-activation redirect and persistent admin notices.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Onboarding {

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_welcome' ) );
		add_action( 'admin_notices', array( $this, 'show_activation_notices' ) );
	}

	/**
	 * On the first admin page load after activation, redirect to the
	 * Pressocampus Settings page with ?welcome=1 so the Settings class
	 * can render the welcome banner.
	 */
	public function maybe_redirect_to_welcome(): void {
		if ( ! get_transient( 'pressocampus_show_welcome' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		delete_transient( 'pressocampus_show_welcome' );

		wp_safe_redirect( admin_url( 'admin.php?page=pressocampus&welcome=1' ) );
		exit;
	}

	/**
	 * Render admin notices for various post-activation and runtime states.
	 */
	public function show_activation_notices(): void {
		// -- sodium missing -----------------------------------------------
		if ( get_option( 'pressocampus_sodium_missing' ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Pressocampus: The sodium PHP extension is not available. Memory encryption is disabled.', 'pressocampus' );
			echo '</p></div>';
		}

		// -- database migration error ------------------------------------
		$migration_error = get_option( 'pressocampus_migration_error' );
		if ( $migration_error ) {
			echo '<div class="notice notice-error"><p>';
			printf(
				/* translators: %s: error message */
				esc_html__( 'Pressocampus database migration failed: %s. Please deactivate and reactivate the plugin.', 'pressocampus' ),
				esc_html( $migration_error )
			);
			echo '</p></div>';
		}

		// -- soul updated, email notification failed ---------------------
		$soul_notice = get_option( 'pressocampus_soul_update_notice' );
		if ( $soul_notice && is_array( $soul_notice ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>';
			printf(
				/* translators: %s: OAuth client / AI name */
				esc_html__( 'Your soul was updated by %s. Email notification could not be sent.', 'pressocampus' ),
				esc_html( $soul_notice['client_name'] ?? __( 'your AI', 'pressocampus' ) )
			);
			echo '</p></div>';
			delete_option( 'pressocampus_soul_update_notice' );
		}

		// -- token expiry notices ----------------------------------------
		// Cache the option-name list so we don't run an unindexed LIKE scan
		// against wp_options on every admin page load (common case: empty list).
		$cache_key           = 'pressocampus_expiry_notices';
		$expiry_option_names = wp_cache_get( $cache_key, 'pressocampus' );

		if ( $expiry_option_names === false ) {
			global $wpdb;
			$expiry_option_names = $wpdb->get_col(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'pressocampus_expiry_notice_%'" // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			);
			wp_cache_set( $cache_key, $expiry_option_names ?: array(), 'pressocampus', 5 * MINUTE_IN_SECONDS );
		}

		foreach ( $expiry_option_names as $opt_name ) {
			$notice_data = get_option( $opt_name );

			if ( ! $notice_data || ! is_array( $notice_data ) ) {
				continue;
			}

			$manage_url = admin_url( 'admin.php?page=pressocampus&tab=advanced' );

			echo '<div class="notice notice-warning is-dismissible"><p>';
			printf(
				wp_kses(
					/* translators: 1: AI/client name, 2: days remaining, 3: settings page URL */
					__( 'Your <strong>%1$s</strong> connection to Pressocampus expires in %2$d days. Ask your AI client to reconnect, or <a href="%3$s">revoke it</a> from Settings → Advanced.', 'pressocampus' ),
					array(
						'strong' => array(),
						'a'      => array( 'href' => array() ),
					)
				),
				esc_html( $notice_data['client_name'] ?? __( 'AI', 'pressocampus' ) ),
				intval( $notice_data['days_left'] ?? 7 ),
				esc_url( $manage_url )
			);
			echo '</p></div>';
		}
	}
}
