<?php
/**
 * Authentication layer — validates Bearer tokens on every pressocampus REST request.
 *
 * Uses setter injection to avoid a circular dependency with OAuthServer:
 *   1. Auth is instantiated first (in Plugin::__construct).
 *   2. OAuthServer is instantiated next and receives Auth.
 *   3. Plugin calls $this->auth->set_oauth_server($this->oauth_server) immediately
 *      after both are constructed.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Auth {

	/**
	 * Static per-request user ID set after successful token validation.
	 *
	 * @var int
	 */
	private static int $current_user_id        = 0;
	private static string $current_client_name = '';
	private static string $current_token_id    = '';

	private ?OAuthServer $oauth_server = null;

	public function __construct(
		private Cache $cache,
	) {
		add_filter( 'rest_authentication_errors', array( $this, 'authenticate_request' ) );
	}

	// -----------------------------------------------------------------------
	// Setter injection (called from Plugin after both objects are constructed)
	// -----------------------------------------------------------------------

	public function set_oauth_server( OAuthServer $server ): void {
		$this->oauth_server = $server;
	}

	// -----------------------------------------------------------------------
	// rest_authentication_errors filter
	// -----------------------------------------------------------------------

	/**
	 * Intercept requests to the pressocampus/v1 namespace.
	 *
	 * - No Authorization header → pass through unchanged (WP handles auth).
	 * - Bearer token present but invalid → 401 WP_Error.
	 * - Bearer token valid → set current user, store static state, return null.
	 */
	public function authenticate_request( mixed $error ): mixed {
		// Only act on our own namespace.
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		if (
			! str_contains( $request_uri, '/pressocampus/v1/' ) &&
			! str_contains( $request_uri, 'pressocampus%2Fv1%2F' )
		) {
			return $error;
		}

		$bearer_token = $this->extract_bearer_token();

		// No token — let WordPress decide (allows wp-login flow for consent screen).
		if ( $bearer_token === null ) {
			return $error;
		}

		// We have a token — validate it with the OAuth resource server.
		if ( $this->oauth_server === null ) {
			// Fallback: build a resource server directly from stored keys.
			// This handles edge cases where set_oauth_server() was not yet called.
			return $this->validate_with_direct_resource_server( $bearer_token );
		}

		$result = $this->oauth_server->validate_bearer_token( $bearer_token );

		if ( is_wp_error( $result ) ) {
			return self::unauthorized_error( 'pressocampus_token_invalid', 'Invalid or expired token.' );
		}

		wp_set_current_user( $result['user_id'] );
		static::$current_user_id     = $result['user_id'];
		static::$current_client_name = $result['client_name'];
		static::$current_token_id    = $result['token_id'];

		return null; // null = allow the request to proceed
	}

	// -----------------------------------------------------------------------
	// Static accessors
	// -----------------------------------------------------------------------

	public static function get_current_user_id(): int {
		return static::$current_user_id;
	}

	public static function get_current_client_name(): string {
		return static::$current_client_name;
	}

	public static function get_current_token_id(): string {
		return static::$current_token_id;
	}

	// -----------------------------------------------------------------------
	// Test helpers (not used in production — set/clear auth state directly)
	// -----------------------------------------------------------------------

	/**
	 * Bypass OAuth validation in unit tests by setting auth state directly.
	 * Only available when running under PHPUnit.
	 */
	public static function set_test_user( int $user_id, string $client_name, string $token_id ): void {
		static::$current_user_id     = $user_id;
		static::$current_client_name = $client_name;
		static::$current_token_id    = $token_id;
		wp_set_current_user( $user_id );
	}

	public static function clear_test_user(): void {
		static::$current_user_id     = 0;
		static::$current_client_name = '';
		static::$current_token_id    = '';
		wp_set_current_user( 0 );
	}

	// -----------------------------------------------------------------------
	// Rate limiting
	// -----------------------------------------------------------------------

	/**
	 * Check whether the current token is under its per-minute rate limit.
	 *
	 * @param string $type 'read' (60/min) or 'write' (30/min).
	 */
	public function check_rate_limit( string $type ): bool {
		$token_id = static::$current_token_id;
		if ( $token_id === '' ) {
			// No authenticated token — allow (other auth layers or public endpoints).
			return true;
		}

		return $this->cache->check_rate_limit( $type, $token_id );
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Get the site hostname, used when constructing URIs.
	 */
	public static function get_site_host(): string {
		return (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
	}

	/**
	 * Build a 401 WP_Error that also carries the authorization endpoint URL.
	 */
	public static function unauthorized_error(
		string $code = 'pressocampus_auth_required',
		string $message = ''
	): \WP_Error {
		if ( $message === '' ) {
			$message = __( 'Authentication required. Please authorize via OAuth.', 'pressocampus' );
		}

		return new \WP_Error(
			$code,
			$message,
			array(
				'status'     => 401,
				'reauth_url' => rest_url( 'pressocampus/v1/oauth/authorize' ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Private
	// -----------------------------------------------------------------------

	/**
	 * Extract the raw Bearer token from the Authorization header.
	 *
	 * Checks in order: Apache getallheaders(), HTTP_AUTHORIZATION server var,
	 * REDIRECT_HTTP_AUTHORIZATION (FastCGI).
	 *
	 * @return string|null Token string, or null if no Bearer header present.
	 */
	private function extract_bearer_token(): ?string {
		$header = '';

		if ( function_exists( 'getallheaders' ) ) {
			$all = getallheaders();
			// getallheaders() keys are title-cased.
			foreach ( $all as $key => $val ) {
				if ( strcasecmp( $key, 'Authorization' ) === 0 ) {
					$header = $val;
					break;
				}
			}
		}

		if ( $header === '' ) {
			$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
		}

		if ( $header === '' ) {
			$header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		}

		if ( $header === '' ) {
			return null;
		}

		if ( ! str_starts_with( strtolower( $header ), 'bearer ' ) ) {
			return null;
		}

		$token = trim( substr( $header, 7 ) );
		return $token !== '' ? $token : null;
	}

	/**
	 * Fallback path: build a ResourceServer directly from stored keys when
	 * OAuthServer has not yet been injected (e.g. during bootstrapping).
	 */
	private function validate_with_direct_resource_server( string $bearer_token ): mixed {
		$public_key_pem = (string) get_option( 'pressocampus_rsa_public_key', '' );
		if ( $public_key_pem === '' ) {
			return self::unauthorized_error( 'pressocampus_token_invalid', 'Invalid or expired token.' );
		}

		try {
			$resource_server = new \League\OAuth2\Server\ResourceServer(
				new OAuth\WPAccessTokenRepository(),
				new \League\OAuth2\Server\CryptKey( $public_key_pem, null, false ),
			);

			$psr_request       = OAuth\WPServerRequest::for_bearer_token( $bearer_token );
			$validated_request = $resource_server->validateAuthenticatedRequest( $psr_request );

			$token_id  = (string) ( $validated_request->getAttribute( 'oauth_access_token_id' ) ?? '' );
			$user_id   = (int) ( $validated_request->getAttribute( 'oauth_user_id' ) ?? 0 );
			$client_id = (string) ( $validated_request->getAttribute( 'oauth_client_id' ) ?? '' );

			// Look up client name.
			global $wpdb;
			$client_name = (string) ( $wpdb->get_var(
				$wpdb->prepare(
					"SELECT name FROM {$wpdb->prefix}pressocampus_oauth_clients WHERE id = %s LIMIT 1",
					$client_id
				)
			) ?? '' );

			wp_set_current_user( $user_id );
			static::$current_user_id     = $user_id;
			static::$current_client_name = $client_name;
			static::$current_token_id    = $token_id;

			return null;

		} catch ( \Throwable ) {
			return self::unauthorized_error( 'pressocampus_token_invalid', 'Invalid or expired token.' );
		}
	}
}
