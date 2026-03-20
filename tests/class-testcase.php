<?php
/**
 * Base test case for Pressocampus tests.
 *
 * Extends the PHPUnit polyfill TestCase directly (NOT WP_UnitTestCase) to
 * avoid the unconditional call to PHPUnit\Util\Test::parseTestMethodAnnotations()
 * inside the WordPress test library's abstract-testcase.php::setUp().  That
 * static method was removed in PHPUnit 10 and causes every test to error
 * regardless of whether @expectedDeprecated annotations are used.
 *
 * Test isolation mirrors what WP_UnitTestCase provides:
 *  - Each test is wrapped in a DB transaction that is rolled back in tear_down.
 *  - The WP object-cache is flushed and the current user is reset after each test.
 *
 * The WP test factory (user, post, …) is made available via a static factory()
 * method that mirrors the WP_UnitTestCase::factory() API without depending on it.
 *
 * @package Pressocampus\Tests
 */

namespace Pressocampus\Tests;

use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

abstract class TestCase extends PolyfillTestCase {

	/** @var \WP_UnitTest_Factory|null Lazily created, shared across tests in a run. */
	private static ?\WP_UnitTest_Factory $wp_factory = null;

	/**
	 * Returns the WP test factory (user, post, comment, …).
	 *
	 * Mirrors WP_UnitTestCase::factory() without inheriting from it.
	 */
	public static function factory(): \WP_UnitTest_Factory {
		if ( null === self::$wp_factory ) {
			self::$wp_factory = new \WP_UnitTest_Factory();
		}
		return self::$wp_factory;
	}

	/**
	 * Starts a DB transaction before each test so all writes are rolled back
	 * in tear_down(), keeping tests fully isolated without truncating tables.
	 */
	protected function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( 'SET autocommit = 0' );
		$wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Rolls back the transaction, flushes the WP object-cache, and clears
	 * the current user after each test.
	 */
	protected function tear_down(): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' );
		$wpdb->query( 'SET autocommit = 1' );
		wp_cache_flush();
		wp_set_current_user( 0 );
		parent::tear_down();
	}
}
