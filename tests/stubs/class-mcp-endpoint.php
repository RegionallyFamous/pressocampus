<?php
/**
 * Stub MCPEndpoint for the test environment.
 *
 * This file is loaded by tests/bootstrap.php when the real
 * includes/class-mcp-endpoint.php has not yet been created (Phase 1 test
 * runs).  It defines only enough to allow Plugin::__construct() to complete
 * without throwing a class-not-found fatal.
 *
 * Once includes/class-mcp-endpoint.php exists this file is never loaded
 * (bootstrap.php checks with file_exists() first).
 *
 * DO NOT use this stub outside of the test bootstrap.
 *
 * @package Pressocampus
 */

namespace Pressocampus;

if ( ! class_exists( MCPEndpoint::class ) ) {
	/**
	 * Stub: replaced by the real MCPEndpoint in Phase 2.
	 */
	class MCPEndpoint {
		/** @param mixed ...$args Accepts any constructor arguments without type-checking. */
		public function __construct( ...$args ) {}
	}
}
