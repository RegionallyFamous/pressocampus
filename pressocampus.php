<?php
/**
 * Plugin Name:       Pressocampus
 * Plugin URI:        https://github.com/RegionallyFamous/pressocampus
 * Description:       Give your AI a permanent memory — stored on your WordPress site, not locked inside any app.
 * Version:           1.1.0
 * Requires at least: 6.4
 * Tested up to:      6.9
 * Requires PHP:      8.3
 * Author:            Regionally Famous
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

define( 'PRESSOCAMPUS_VERSION', '1.1.0' );
define( 'PRESSOCAMPUS_DB_VERSION', '1.2' );
define( 'PRESSOCAMPUS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSOCAMPUS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSOCAMPUS_PLUGIN_FILE', __FILE__ );
define( 'PRESSOCAMPUS_CPT', 'pressocampus_mem' );
define( 'PRESSOCAMPUS_TAXONOMY', 'pressocampus_group' );
define( 'PRESSOCAMPUS_SCOPE', 'pressocampus:memory' );

// OAuth token TTLs — ISO 8601 duration strings.
define( 'PRESSOCAMPUS_AUTH_CODE_TTL', 'PT10M' );    // 10 minutes
define( 'PRESSOCAMPUS_ACCESS_TOKEN_TTL', 'PT8H' );  // 8 hours
define( 'PRESSOCAMPUS_REFRESH_TOKEN_TTL', 'P30D' ); // 30 days

// ---------------------------------------------------------------------------
// Composer autoloader (vendor/autoload.php handles league/oauth2-server etc.)
// ---------------------------------------------------------------------------

if ( ! file_exists( PRESSOCAMPUS_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	// Vendor directory is missing — the plugin was likely installed without running
	// `composer install`. Flag it and bail out; admin_init will deactivate and notify.
	update_option( 'pressocampus_vendor_missing', true );

	add_action(
		'admin_init',
		static function (): void {
			if ( ! function_exists( 'deactivate_plugins' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			deactivate_plugins( plugin_basename( PRESSOCAMPUS_PLUGIN_FILE ) );
		}
	);

	add_action(
		'admin_notices',
		static function (): void {
			echo '<div class="notice notice-error"><p>'
				. esc_html__( 'Pressocampus: The vendor directory is missing. Please run `composer install` in the plugin directory, then reactivate the plugin.', 'pressocampus' )
				. '</p></div>';
		}
	);

	return; // Stop loading the rest of the plugin.
}

delete_option( 'pressocampus_vendor_missing' );
require_once PRESSOCAMPUS_PLUGIN_DIR . 'vendor/autoload.php';

// ---------------------------------------------------------------------------
// Map-based PSR-4-style autoloader for plugin classes
// ---------------------------------------------------------------------------

function pressocampus_autoload( string $class_name ): void {
	$map = array(
		'Pressocampus\\Installer'                       => 'includes/class-installer.php',
		'Pressocampus\\Plugin'                          => 'includes/class-plugin.php',
		'Pressocampus\\CPT'                             => 'includes/class-cpt.php',
		'Pressocampus\\MCPEndpoint'                     => 'includes/class-mcp-endpoint.php',
		'Pressocampus\\Auth'                            => 'includes/class-auth.php',
		'Pressocampus\\OAuthServer'                     => 'includes/class-oauth-server.php',
		'Pressocampus\\AuditLog'                        => 'includes/class-audit-log.php',
		'Pressocampus\\Discovery'                       => 'includes/class-discovery.php',
		'Pressocampus\\ResourceIndex'                   => 'includes/class-resource-index.php',
		'Pressocampus\\Cache'                           => 'includes/class-cache.php',
		'Pressocampus\\Soul'                            => 'includes/class-soul.php',
		'Pressocampus\\Onboarding'                      => 'includes/class-onboarding.php',
		'Pressocampus\\Settings'                        => 'includes/class-settings.php',
		'Pressocampus\\OAuth\\WPClientRepository'       => 'includes/oauth/class-wp-client-repository.php',
		'Pressocampus\\OAuth\\WPAccessTokenRepository'  => 'includes/oauth/class-wp-access-token-repository.php',
		'Pressocampus\\OAuth\\WPAuthCodeRepository'     => 'includes/oauth/class-wp-auth-code-repository.php',
		'Pressocampus\\OAuth\\WPRefreshTokenRepository' => 'includes/oauth/class-wp-refresh-token-repository.php',
		'Pressocampus\\OAuth\\WPScopeRepository'        => 'includes/oauth/class-wp-scope-repository.php',
		'Pressocampus\\OAuth\\UserEntity'               => 'includes/oauth/class-user-entity.php',
		'Pressocampus\\OAuth\\WPStream'                 => 'includes/oauth/class-psr7-bridge.php',
		'Pressocampus\\OAuth\\WPUri'                    => 'includes/oauth/class-psr7-bridge.php',
		'Pressocampus\\OAuth\\WPResponse'               => 'includes/oauth/class-psr7-bridge.php',
		'Pressocampus\\OAuth\\WPServerRequest'          => 'includes/oauth/class-psr7-bridge.php',
	);
	if ( isset( $map[ $class_name ] ) ) {
		require_once PRESSOCAMPUS_PLUGIN_DIR . $map[ $class_name ];
	}
}
spl_autoload_register( 'pressocampus_autoload' );

// ---------------------------------------------------------------------------
// Activation / deactivation hooks
// ---------------------------------------------------------------------------

register_activation_hook( PRESSOCAMPUS_PLUGIN_FILE, array( 'Pressocampus\\Installer', 'activate' ) );
register_deactivation_hook( PRESSOCAMPUS_PLUGIN_FILE, array( 'Pressocampus\\Installer', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Boot the plugin
// ---------------------------------------------------------------------------

add_action(
	'plugins_loaded',
	static function (): void {
		Pressocampus\Plugin::get_instance();
	},
	10
);

// ---------------------------------------------------------------------------
// WP-CLI commands
// ---------------------------------------------------------------------------

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	add_action(
		'cli_init',
		static function (): void {
			$wp_cli_file = PRESSOCAMPUS_PLUGIN_DIR . 'bin/wp-cli.php';
			if ( file_exists( $wp_cli_file ) ) {
				require_once $wp_cli_file;
			}
		}
	);
}
