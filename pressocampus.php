<?php
/**
 * Plugin Name:       Pressocampus
 * Plugin URI:        https://github.com/pressocampus/pressocampus
 * Description:       Turn WordPress into your AI's persistent memory store. Implements MCP Protocol (2025-03-26) over WordPress REST API with OAuth 2.1.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      8.3
 * Author:            Pressocampus Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressocampus
 * Domain Path:       /languages
 *
 * @package Pressocampus
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------

define( 'PRESSOCAMPUS_VERSION',    '1.0.0' );
define( 'PRESSOCAMPUS_DB_VERSION', '1.0' );
define( 'PRESSOCAMPUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSOCAMPUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSOCAMPUS_PLUGIN_FILE', __FILE__ );
define( 'PRESSOCAMPUS_CPT',       'pressocampus_mem' );
define( 'PRESSOCAMPUS_TAXONOMY',  'pressocampus_group' );
define( 'PRESSOCAMPUS_SCOPE',     'pressocampus:memory' );

// ---------------------------------------------------------------------------
// Composer autoloader (vendor/autoload.php handles league/oauth2-server etc.)
// ---------------------------------------------------------------------------

if ( file_exists( PRESSOCAMPUS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once PRESSOCAMPUS_PLUGIN_DIR . 'vendor/autoload.php';
}

// ---------------------------------------------------------------------------
// Map-based PSR-4-style autoloader for plugin classes
// ---------------------------------------------------------------------------

function pressocampus_autoload( string $class ): void {
	$map = [
		'Pressocampus\\Installer'    => 'includes/class-installer.php',
		'Pressocampus\\Plugin'       => 'includes/class-plugin.php',
		'Pressocampus\\CPT'          => 'includes/class-cpt.php',
		'Pressocampus\\MCPEndpoint'  => 'includes/class-mcp-endpoint.php',
		'Pressocampus\\Auth'         => 'includes/class-auth.php',
		'Pressocampus\\OAuthServer'  => 'includes/class-oauth-server.php',
		'Pressocampus\\AuditLog'     => 'includes/class-audit-log.php',
		'Pressocampus\\Discovery'    => 'includes/class-discovery.php',
		'Pressocampus\\ResourceIndex' => 'includes/class-resource-index.php',
		'Pressocampus\\Cache'        => 'includes/class-cache.php',
		'Pressocampus\\Soul'         => 'includes/class-soul.php',
		'Pressocampus\\Onboarding'   => 'includes/class-onboarding.php',
		'Pressocampus\\Settings'     => 'includes/class-settings.php',
		'Pressocampus\\OAuth\\WPClientRepository'       => 'includes/oauth/class-wp-client-repository.php',
		'Pressocampus\\OAuth\\WPAccessTokenRepository'  => 'includes/oauth/class-wp-access-token-repository.php',
		'Pressocampus\\OAuth\\WPAuthCodeRepository'     => 'includes/oauth/class-wp-auth-code-repository.php',
		'Pressocampus\\OAuth\\WPRefreshTokenRepository' => 'includes/oauth/class-wp-refresh-token-repository.php',
		'Pressocampus\\OAuth\\WPScopeRepository'        => 'includes/oauth/class-wp-scope-repository.php',
		'Pressocampus\\OAuth\\UserEntity'               => 'includes/oauth/class-user-entity.php',
	];
	if ( isset( $map[ $class ] ) ) {
		require_once PRESSOCAMPUS_PLUGIN_DIR . $map[ $class ];
	}
}
spl_autoload_register( 'pressocampus_autoload' );

// ---------------------------------------------------------------------------
// Activation / deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook(   PRESSOCAMPUS_PLUGIN_FILE, [ 'Pressocampus\\Installer', 'activate' ] );
register_deactivation_hook( PRESSOCAMPUS_PLUGIN_FILE, [ 'Pressocampus\\Installer', 'deactivate' ] );

// ---------------------------------------------------------------------------
// Boot the plugin
// ---------------------------------------------------------------------------

add_action( 'plugins_loaded', static function (): void {
	Pressocampus\Plugin::get_instance();
}, 10 );

// ---------------------------------------------------------------------------
// WP-CLI commands
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action( 'cli_init', static function (): void {
		require_once PRESSOCAMPUS_PLUGIN_DIR . 'bin/wp-cli.php';
	} );
}
