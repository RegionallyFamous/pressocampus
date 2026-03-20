<?php
/**
 * PHPUnit bootstrap for Pressocampus tests.
 *
 * Requires the WordPress test library.  The simplest way to install it:
 *
 *   bash bin/install-wp-tests.sh wordpress_test root '' localhost latest
 *
 * Environment variables (or set in phpunit.xml):
 *   WP_TESTS_DIR  – path to the WP test library  (default /tmp/wordpress-tests-lib)
 *   WP_CORE_DIR   – path to a WordPress install   (default /tmp/wordpress)
 */

// Mark this as a test environment so Auth::set_test_user() / clear_test_user() are enabled.
define( 'PRESSOCAMPUS_TESTING', true );

$_tests_dir  = getenv( 'WP_TESTS_DIR' ) ?: '/tmp/wordpress-tests-lib';
$_plugin_dir = dirname( __DIR__ );

// PHPUnit Polyfills — required by the WP test bootstrap (WP 5.9+).
// Load from the Composer-managed location so no manual path is needed.
$_polyfills_autoload = $_plugin_dir . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';
if ( ! file_exists( $_polyfills_autoload ) ) {
	echo "PHPUnit Polyfills not found. Run: composer install\n";
	exit( 1 );
}
require_once $_polyfills_autoload;

if ( ! file_exists( $_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find WordPress test library in {$_tests_dir}\n";
	echo "Set WP_TESTS_DIR or install via bin/install-wp-tests.sh.\n";
	exit( 1 );
}

require_once $_tests_dir . '/includes/functions.php';

/**
 * Load Pressocampus before WordPress finishes bootstrapping.
 *
 * We also provide a stub MCPEndpoint so that Plugin::__construct() can
 * complete.  The stub is only needed when class-mcp-endpoint.php has not
 * yet been implemented (Phase 2).  Once that file exists the stub is
 * automatically bypassed.
 */
function _manually_load_plugin(): void {
	$plugin_dir = dirname( __DIR__ ) . '/';

	// Provide a stub so Plugin can boot even before MCPEndpoint is written.
	if ( ! file_exists( $plugin_dir . 'includes/class-mcp-endpoint.php' ) ) {
		require __DIR__ . '/stubs/class-mcp-endpoint.php';
	}

	// Boot the plugin (registers constants, autoloader, activation hooks,
	// and the plugins_loaded action that creates Plugin::get_instance()).
	require $plugin_dir . 'pressocampus.php';

	// Create the custom DB tables so test queries don't fail.
	\Pressocampus\Installer::run_migrations();
}

tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

require $_tests_dir . '/includes/bootstrap.php';
