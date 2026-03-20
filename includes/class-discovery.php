<?php
/**
 * Discovery — serves /.well-known/mcp.json.
 *
 * Note: /.well-known/oauth-authorization-server is handled by OAuthServer.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Discovery {

	public function __construct() {
		add_action( 'parse_request', array( $this, 'handle_well_known_mcp' ) );
	}

	/**
	 * Intercept requests for /.well-known/mcp.json and return the MCP
	 * discovery document as JSON.
	 *
	 * @param \WP $wp Current WordPress environment instance.
	 */
	public function handle_well_known_mcp( \WP $wp ): void {
		$request = trim( $wp->request, '/' );

		if ( $request !== '.well-known/mcp.json' ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );

		$data = array(
			'version'  => '2025-03-26',
			'endpoint' => home_url( '/brain' ),
			'name'     => get_bloginfo( 'name' ) . ' — Pressocampus',
			'auth'     => array(
				'type'                 => 'oauth2',
				'authorization_server' => home_url( '/.well-known/oauth-authorization-server' ),
			),
		);

		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
