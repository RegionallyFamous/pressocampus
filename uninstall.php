<?php
/**
 * Uninstall handler — runs when the plugin is deleted from the WP admin.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Perform all cleanup tasks when the plugin is deleted.
 */
function pressocampus_uninstall(): void {
	global $wpdb;

	// Drop custom tables.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pressocampus_resource_index" );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pressocampus_audit_log" );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pressocampus_oauth_clients" );
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}pressocampus_oauth_tokens" );

	// Delete all memory posts (CPT).
	$pressocampus_posts = get_posts(
		array(
			'post_type'   => 'pressocampus_resource',
			'numberposts' => -1,
			'post_status' => 'any',
		)
	);
	foreach ( $pressocampus_posts as $pressocampus_post ) {
		wp_delete_post( $pressocampus_post->ID, true );
	}

	// Fetch service user id before deleting options.
	$pressocampus_service_user_id = (int) get_option( 'pressocampus_service_user_id' );

	// Delete options.
	$pressocampus_options = array(
		'pressocampus_db_version',
		'pressocampus_rsa_private_key',
		'pressocampus_rsa_public_key',
		'pressocampus_service_user_id',
		'pressocampus_sodium_missing',
		'pressocampus_migration_error',
		'pressocampus_settings',
	);
	foreach ( $pressocampus_options as $pressocampus_option ) {
		delete_option( $pressocampus_option );
	}

	// Delete service user.
	if ( $pressocampus_service_user_id > 0 ) {
		wp_delete_user( $pressocampus_service_user_id );
	}

	// Remove custom role.
	remove_role( 'pressocampus_agent' );

	// Flush rewrite rules.
	flush_rewrite_rules();
}

pressocampus_uninstall();
