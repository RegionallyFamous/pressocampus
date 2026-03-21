<?php
/**
 * OAuth 2.1 server — registers REST routes and drives the full PKCE flow.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Pressocampus\OAuth\UserEntity;
use Pressocampus\OAuth\WPAccessTokenRepository;
use Pressocampus\OAuth\WPAuthCodeRepository;
use Pressocampus\OAuth\WPClientRepository;
use Pressocampus\OAuth\WPRefreshTokenRepository;
use Pressocampus\OAuth\WPResponse;
use Pressocampus\OAuth\WPScopeRepository;
use Pressocampus\OAuth\WPServerRequest;

class OAuthServer {

	private const REST_NAMESPACE = 'pressocampus/v1';

	private ?AuthorizationServer $authorization_server = null;
	private ?ResourceServer $resource_server           = null;

	public function __construct(
		private Auth $auth,
		private AuditLog $audit_log,
	) {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'parse_request', array( $this, 'handle_well_known' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_register' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/authorize',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'handle_authorize_form' ),
					'permission_callback' => '__return_true',
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'handle_authorize_submit' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// /.well-known/oauth-authorization-server

	public function handle_well_known(): void {
		$request_uri = $_SERVER['REQUEST_URI'] ?? '';
		$path        = (string) wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( $path !== '/.well-known/oauth-authorization-server' ) {
			return;
		}

		$base = rest_url( self::REST_NAMESPACE . '/oauth' );

		$document = array(
			'issuer'                                => home_url(),
			'authorization_endpoint'                => $base . '/authorize',
			'token_endpoint'                        => $base . '/token',
			'registration_endpoint'                 => $base . '/register',
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'scopes_supported'                      => array( PRESSOCAMPUS_SCOPE ),
			'token_endpoint_auth_methods_supported' => array( 'client_secret_post', 'none' ),
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $document );
		exit;
	}

	// POST /oauth/register  — RFC 7591 dynamic client registration

	public function handle_register( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		// Rate-limit dynamic client registration: 10 registrations per IP per hour.
		// This prevents automated abuse of the open registration endpoint.
		$ip_hash   = md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$reg_key   = 'pc_reg_' . $ip_hash;
		$reg_count = (int) get_transient( $reg_key );

		if ( $reg_count >= 10 ) {
			return new \WP_Error(
				'too_many_requests',
				'Too many client registration attempts from this address. Please try again later.',
				array( 'status' => 429 )
			);
		}

		set_transient( $reg_key, $reg_count + 1, HOUR_IN_SECONDS );

		$params      = $request->get_json_params() ?: $request->get_body_params();
		$client_name = sanitize_text_field( $params['client_name'] ?? '' );
		$raw_uris    = $params['redirect_uris'] ?? array();

		if ( $client_name === '' ) {
			return new \WP_Error(
				'invalid_client_metadata',
				'client_name is required.',
				array( 'status' => 400 )
			);
		}

		if ( ! is_array( $raw_uris ) || empty( $raw_uris ) ) {
			return new \WP_Error(
				'invalid_client_metadata',
				'redirect_uris must be a non-empty array.',
				array( 'status' => 400 )
			);
		}

		$redirect_uris = array_values(
			array_filter(
				array_map( 'esc_url_raw', $raw_uris ),
				fn( $u ) => $u !== ''
			)
		);

		if ( empty( $redirect_uris ) ) {
			return new \WP_Error(
				'invalid_client_metadata',
				'At least one valid redirect_uri is required.',
				array( 'status' => 400 )
			);
		}

		// Public clients (no secret) are the default for AI agents using PKCE.
		$is_confidential = ( $params['token_endpoint_auth_method'] ?? '' ) === 'client_secret_post';

		$result = OAuth\WPClientRepository::register(
			$client_name,
			$redirect_uris,
			$is_confidential,
			get_current_user_id()
		);

		$response_body = array(
			'client_id'                  => $result['client_id'],
			'client_name'                => $client_name,
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'token_endpoint_auth_method' => $is_confidential ? 'client_secret_post' : 'none',
			'scope'                      => PRESSOCAMPUS_SCOPE,
		);

		if ( $is_confidential ) {
			$response_body['client_secret'] = $result['client_secret'];
		}

		$this->audit_log->log(
			'oauth_client_registered',
			array(
				'client_id' => $result['client_id'],
				'name'      => $client_name,
			)
		);

		return new \WP_REST_Response( $response_body, 201 );
	}

	// GET /oauth/authorize  — show the consent screen

	public function handle_authorize_form( \WP_REST_Request $request ): void {
		$params = $request->get_query_params();

		$client_id             = sanitize_text_field( $params['client_id'] ?? '' );
		$redirect_uri          = esc_url_raw( $params['redirect_uri'] ?? '' );
		$state                 = sanitize_text_field( $params['state'] ?? '' );
		$code_challenge        = sanitize_text_field( $params['code_challenge'] ?? '' );
		$code_challenge_method = sanitize_text_field( $params['code_challenge_method'] ?? 'S256' );
		$response_type         = sanitize_text_field( $params['response_type'] ?? 'code' );
		$scope                 = sanitize_text_field( $params['scope'] ?? PRESSOCAMPUS_SCOPE );

		// Validate the client and redirect URI before showing the form.
		$client = ( new OAuth\WPClientRepository() )->getClientEntity( $client_id );
		if ( $client === null ) {
			wp_die(
				esc_html__( 'Unknown OAuth client.', 'pressocampus' ),
				esc_html__( 'Authorization Error', 'pressocampus' ),
				array( 'response' => 400 )
			);
		}

		$allowed_uris = (array) $client->getRedirectUri();
		if ( $redirect_uri !== '' && ! in_array( $redirect_uri, $allowed_uris, true ) ) {
			wp_die(
				esc_html__( 'Invalid redirect_uri.', 'pressocampus' ),
				esc_html__( 'Authorization Error', 'pressocampus' ),
				array( 'response' => 400 )
			);
		}

		// Use the first registered URI if none was provided.
		if ( $redirect_uri === '' ) {
			$redirect_uri = $allowed_uris[0] ?? '';
		}

		$site_name   = get_bloginfo( 'name' );
		$client_name = esc_html( $client->getName() );
		$nonce       = wp_create_nonce( 'pressocampus_authorize_' . $client_id );

		// When this endpoint is reached via a browser redirect (e.g. from wp-login.php),
		// WordPress's REST cookie checker calls wp_set_current_user(0) because there is
		// no X-WP-Nonce header — even though the auth cookie is valid. The OAuth consent
		// flow has its own CSRF protection (the `state` parameter), so we validate the
		// cookie ourselves to restore the authenticated user for this request.
		if ( ! is_user_logged_in() ) {
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		// If the user is not logged in, redirect them to the WP login page.
		if ( ! is_user_logged_in() ) {
			$current_url = rest_url( self::REST_NAMESPACE . '/oauth/authorize' ) . '?' . http_build_query( $params );
			wp_safe_redirect( wp_login_url( $current_url ) );
			exit;
		}

		$current_user = wp_get_current_user();
		$username     = esc_html( $current_user->display_name ?: $current_user->user_login );

		// Hidden fields to pass through the OAuth parameters.
		// _wpnonce must be a valid wp_rest nonce so that WordPress's REST cookie
		// checker passes when the form is submitted (it validates $_REQUEST['_wpnonce']
		// against the 'wp_rest' action).  Our own form-integrity nonce is stored in
		// _pc_nonce under a different action so the two don't collide.
		$hidden_fields = $this->build_hidden_fields(
			array(
				'client_id'             => $client_id,
				'redirect_uri'          => $redirect_uri,
				'state'                 => $state,
				'code_challenge'        => $code_challenge,
				'code_challenge_method' => $code_challenge_method,
				'response_type'         => $response_type,
				'scope'                 => $scope,
				'_wpnonce'              => wp_create_nonce( 'wp_rest' ),
				'_pc_nonce'             => $nonce,
			)
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- render_consent_page returns fully escaped HTML; hidden_fields is built by build_hidden_fields() which escapes each value with esc_attr()
		echo $this->render_consent_page(
			array(
				'site_name'     => esc_html( $site_name ),
				'client_name'   => esc_html( $client_name ),
				'username'      => esc_html( $username ),
				'hidden_fields' => $hidden_fields,
			)
		);
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped

		exit;
	}

	// POST /oauth/authorize  — process the consent form submission

	public function handle_authorize_submit( \WP_REST_Request $request ): void {
		$params    = $request->get_body_params();
		$client_id = sanitize_text_field( $params['client_id'] ?? '' );
		$nonce     = $params['_pc_nonce'] ?? '';

		if ( ! wp_verify_nonce( $nonce, 'pressocampus_authorize_' . $client_id ) ) {
			wp_die(
				esc_html__( 'Security check failed. Please try again.', 'pressocampus' ),
				esc_html__( 'Authorization Error', 'pressocampus' ),
				array( 'response' => 403 )
			);
		}

		// Same cookie-restoration as the GET handler: the REST API requires a nonce for
		// cookie auth, but the wp_rest nonce in _wpnonce satisfies that check above.
		// Restore the user in case anything reset it before this handler ran.
		if ( ! is_user_logged_in() ) {
			$user_id = wp_validate_auth_cookie( '', 'logged_in' );
			if ( $user_id ) {
				wp_set_current_user( $user_id );
			}
		}

		if ( ! is_user_logged_in() ) {
			wp_die(
				esc_html__( 'You must be logged in to authorize this request.', 'pressocampus' ),
				esc_html__( 'Authorization Error', 'pressocampus' ),
				array( 'response' => 401 )
			);
		}

		$redirect_uri = esc_url_raw( $params['redirect_uri'] ?? '' );
		$state        = sanitize_text_field( $params['state'] ?? '' );

		// User clicked Deny.
		if ( isset( $params['deny'] ) ) {
			$redirect = add_query_arg(
				array_filter(
					array(
						'error'             => 'access_denied',
						'error_description' => 'The user denied access.',
						'state'             => $state,
					)
				),
				$redirect_uri
			);
			wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri is already validated against registered client URIs by league/oauth2-server
			exit;
		}

		// User clicked Allow — build PSR-7 request from the POST-ed OAuth params
		// and run it through the AuthorizationServer.
		$oauth_params = array(
			'response_type'         => sanitize_text_field( $params['response_type'] ?? 'code' ),
			'client_id'             => $client_id,
			'redirect_uri'          => $redirect_uri,
			'scope'                 => sanitize_text_field( $params['scope'] ?? PRESSOCAMPUS_SCOPE ),
			'state'                 => $state,
			'code_challenge'        => sanitize_text_field( $params['code_challenge'] ?? '' ),
			'code_challenge_method' => sanitize_text_field( $params['code_challenge_method'] ?? 'S256' ),
		);

		// league/oauth2-server reads these from getQueryParams().
		$psr_request = ( new OAuth\WPServerRequest(
			'GET',
			rest_url( self::REST_NAMESPACE . '/oauth/authorize' ) . '?' . http_build_query( $oauth_params ),
			array(),
			$_SERVER,
			$oauth_params,
			null,
			''
		) );

		try {
			$server       = $this->get_authorization_server();
			$auth_request = $server->validateAuthorizationRequest( $psr_request );

			$user_entity = OAuth\UserEntity::from_wp_user_id( get_current_user_id() );
			$auth_request->setUser( $user_entity );
			$auth_request->setAuthorizationApproved( true );

			$psr_response = $server->completeAuthorizationRequest( $auth_request, new WPResponse() );

			$location = $psr_response->getHeaderLine( 'Location' );
			if ( $location === '' ) {
				wp_die(
					esc_html__( 'OAuth server did not return a redirect location.', 'pressocampus' ),
					'',
					array( 'response' => 500 )
				);
			}

			$this->audit_log->log(
				'oauth_authorized',
				array(
					'client_id' => $client_id,
					'user_id'   => get_current_user_id(),
				)
			);

			wp_redirect( $location ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- location is generated by league/oauth2-server which validates it against the registered redirect_uris
			exit;

		} catch ( OAuthServerException $e ) {
			// Do not forward getHint() — it can expose internal SQL fragments,
			// stack traces, or token values to the redirect target.
			$redirect = add_query_arg(
				array_filter(
					array(
						'error'             => $e->getErrorType(),
						'error_description' => $e->getMessage(),
						'state'             => $state,
					)
				),
				$redirect_uri
			);
			wp_redirect( $redirect ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- redirect_uri validated against registered client URIs
			exit;
		} catch ( \Throwable $e ) {
			// Catch \RuntimeException from missing RSA key, \BadFormatException from
			// Defuse, and any other unexpected errors — never expose raw exception details.
			wp_die(
				esc_html__( 'Authorization failed. Please contact the site administrator.', 'pressocampus' ),
				esc_html__( 'Authorization Error', 'pressocampus' ),
				array( 'response' => 500 )
			);
		}
	}

	// POST /oauth/token  — exchange code / refresh token for tokens

	public function handle_token( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		try {
			$psr_request  = WPServerRequest::from_wp_request( $request );
			$psr_response = $this->get_authorization_server()->respondToAccessTokenRequest(
				$psr_request,
				new WPResponse()
			);

			$body = json_decode( (string) $psr_response->getBody(), true );

			$wp_response = new \WP_REST_Response( $body, $psr_response->getStatusCode() );
			foreach ( $psr_response->getHeaders() as $header_name => $values ) {
				$wp_response->header( $header_name, implode( ', ', $values ) );
			}

			$this->audit_log->log(
				'oauth_token_issued',
				array(
					'client_id'  => $body['client_id'] ?? '',
					'grant_type' => $request->get_param( 'grant_type' ),
				)
			);

			return $wp_response;

		} catch ( OAuthServerException $e ) {
			$psr_response = $e->generateHttpResponse( new WPResponse() );
			$body         = json_decode( (string) $psr_response->getBody(), true );
			return new \WP_REST_Response( $body, $psr_response->getStatusCode() );
		} catch ( \Throwable $e ) {
			return new \WP_REST_Response(
				array(
					'error'             => 'server_error',
					'error_description' => 'An internal error occurred. Please contact the site administrator.',
				),
				500
			);
		}
	}

	// Token expiry notifications (called by cron)

	public function notify_expiring_tokens(): void {
		global $wpdb;

		$soon = gmdate( 'Y-m-d H:i:s', strtotime( '+7 days' ) );
		$now  = current_time( 'mysql', true );

		$tokens = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.client_id, t.user_id, t.expires_at, c.name AS client_name
                 FROM {$wpdb->prefix}pressocampus_oauth_tokens AS t
                 LEFT JOIN {$wpdb->prefix}pressocampus_oauth_clients AS c ON t.client_id = c.id
                 WHERE t.type = 'refresh'
                   AND t.revoked = 0
                   AND t.expires_at > %s
                   AND t.expires_at <= %s",
				$now,
				$soon
			)
		);

		if ( empty( $tokens ) ) {
			return;
		}

		foreach ( $tokens as $token ) {
			$user = get_userdata( (int) $token->user_id );
			if ( ! $user instanceof \WP_User ) {
				continue;
			}

			$days_left = (int) floor( ( strtotime( $token->expires_at ) - time() ) / DAY_IN_SECONDS );

			$subject = sprintf(
				/* translators: %s: site name */
				__( '[%s] OAuth refresh token expiring soon', 'pressocampus' ),
				get_bloginfo( 'name' )
			);

			$message = sprintf(
				/* translators: 1: client name, 2: site name, 3: days remaining */
				__( 'The connection for "%1$s" on %2$s expires in %3$d days. Ask your AI client to reconnect, or revoke it from Pressocampus → Settings → Advanced.', 'pressocampus' ),
				$token->client_name,
				get_bloginfo( 'name' ),
				$days_left
			);

			$sent = wp_mail( $user->user_email, $subject, $message );

			if ( ! $sent ) {
				update_option(
					'pressocampus_expiry_notice_' . $token->client_id,
					array(
						'client_name' => $token->client_name,
						'days_left'   => $days_left,
						'noticed_at'  => current_time( 'mysql', true ),
					)
				);
			}
		}
	}

	public function get_authorization_server(): AuthorizationServer {
		if ( $this->authorization_server instanceof AuthorizationServer ) {
			return $this->authorization_server;
		}

		$private_key_pem = (string) get_option( 'pressocampus_rsa_private_key', '' );
		$public_key_pem  = (string) get_option( 'pressocampus_rsa_public_key', '' );
		$enc_key_ascii   = (string) get_option( 'pressocampus_encryption_key', '' );

		if ( $enc_key_ascii === '' ) {
			$enc_key       = Key::createNewRandomKey();
			$enc_key_ascii = $enc_key->saveToAsciiSafeString();
			update_option( 'pressocampus_encryption_key', $enc_key_ascii, false );
		} else {
			$enc_key = Key::loadFromAsciiSafeString( $enc_key_ascii );
		}

		$private_crypt_key = new CryptKey( $private_key_pem, null, false );

		$client_repo        = new OAuth\WPClientRepository();
		$access_token_repo  = new OAuth\WPAccessTokenRepository();
		$scope_repo         = new OAuth\WPScopeRepository();
		$auth_code_repo     = new OAuth\WPAuthCodeRepository();
		$refresh_token_repo = new OAuth\WPRefreshTokenRepository();

		$server = new AuthorizationServer(
			$client_repo,
			$access_token_repo,
			$scope_repo,
			$private_crypt_key,
			$enc_key,
		);

		$auth_code_grant = new AuthCodeGrant(
			$auth_code_repo,
			$refresh_token_repo,
			new DateInterval( PRESSOCAMPUS_AUTH_CODE_TTL )
		);
		// PKCE is required for public clients by default.
		// For OAuth 2.1 compliance we enforce it; public clients MUST supply a challenge.
		$auth_code_grant->setRefreshTokenTTL( new DateInterval( PRESSOCAMPUS_REFRESH_TOKEN_TTL ) );
		$server->enableGrantType( $auth_code_grant, new DateInterval( PRESSOCAMPUS_ACCESS_TOKEN_TTL ) );

		$refresh_token_grant = new RefreshTokenGrant( $refresh_token_repo );
		$refresh_token_grant->setRefreshTokenTTL( new DateInterval( PRESSOCAMPUS_REFRESH_TOKEN_TTL ) );
		$server->enableGrantType( $refresh_token_grant, new DateInterval( PRESSOCAMPUS_ACCESS_TOKEN_TTL ) );

		$this->authorization_server = $server;
		return $this->authorization_server;
	}

	public function get_resource_server(): ResourceServer {
		if ( $this->resource_server instanceof ResourceServer ) {
			return $this->resource_server;
		}

		$public_key_pem   = (string) get_option( 'pressocampus_rsa_public_key', '' );
		$public_crypt_key = new CryptKey( $public_key_pem, null, false );

		$this->resource_server = new ResourceServer(
			new OAuth\WPAccessTokenRepository(),
			$public_crypt_key,
		);

		return $this->resource_server;
	}

	/**
	 * Validate a Bearer token extracted from an Authorization header.
	 *
	 * @param string $bearer_token Raw token string (without "Bearer " prefix).
	 * @return array{user_id: int, client_name: string, token_id: string}|\WP_Error
	 */
	public function validate_bearer_token( string $bearer_token ): array|\WP_Error {
		try {
			$psr_request       = WPServerRequest::for_bearer_token( $bearer_token );
			$validated_request = $this->get_resource_server()->validateAuthenticatedRequest( $psr_request );

			$token_id  = (string) ( $validated_request->getAttribute( 'oauth_access_token_id' ) ?? '' );
			$user_id   = (int) ( $validated_request->getAttribute( 'oauth_user_id' ) ?? 0 );
			$client_id = (string) ( $validated_request->getAttribute( 'oauth_client_id' ) ?? '' );

			$client_name = $this->get_client_name( $client_id );

			return array(
				'user_id'     => $user_id,
				'client_name' => $client_name,
				'token_id'    => $token_id,
			);

		} catch ( OAuthServerException $e ) {
			return new \WP_Error(
				'pressocampus_token_invalid',
				$e->getMessage(),
				array( 'status' => $e->getHttpStatusCode() )
			);
		} catch ( \Throwable $e ) {
			return new \WP_Error(
				'pressocampus_token_error',
				'Token validation failed.',
				array( 'status' => 401 )
			);
		}
	}

	private function get_client_name( string $client_id ): string {
		if ( $client_id === '' ) {
			return '';
		}

		global $wpdb;

		$name = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT name FROM {$wpdb->prefix}pressocampus_oauth_clients WHERE id = %s LIMIT 1",
				$client_id
			)
		);

		return (string) ( $name ?? '' );
	}

	/**
	 * Render the HTML consent screen.
	 *
	 * @param array{site_name: string, client_name: string, username: string, hidden_fields: string} $vars Template variables: site_name, client_name, username, hidden_fields.
	 */
	private function render_consent_page( array $vars ): string {
		$submit_url = esc_url( rest_url( self::REST_NAMESPACE . '/oauth/authorize' ) );

		$lbl_title = esc_html(
			sprintf(
				/* translators: %s: site name */
				__( 'Authorize — %s', 'pressocampus' ),
				$vars['site_name']
			)
		);
		$lbl_heading     = 'Press<span>ocampus</span>';
		$lbl_description = sprintf(
			wp_kses(
				/* translators: 1: client name, 2: site name */
				__( '<strong>%1$s</strong> wants to read, write, and delete your memories on <strong>%2$s</strong>.', 'pressocampus' ),
				array( 'strong' => array() )
			),
			esc_html( $vars['client_name'] ),
			esc_html( $vars['site_name'] )
		);
		$lbl_scope_read   = esc_html__( 'Read all stored memories', 'pressocampus' );
		$lbl_scope_create = esc_html__( 'Create new memories', 'pressocampus' );
		$lbl_scope_edit   = esc_html__( 'Update and delete memories', 'pressocampus' );
		$lbl_allow        = esc_attr__( 'Allow', 'pressocampus' );
		$lbl_deny         = esc_attr__( 'Deny', 'pressocampus' );
		$lbl_signed_in    = esc_html(
			sprintf(
				/* translators: %s: username */
				__( 'Signed in as %s', 'pressocampus' ),
				$vars['username']
			)
		);

		return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$lbl_title}</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f0f1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            box-shadow: 0 2px 12px rgba(0,0,0,.12);
            max-width: 480px;
            width: 100%;
            padding: 2rem;
        }
        .header {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .header .brain { font-size: 2.5rem; display: block; margin-bottom: .5rem; }
        .header h1 { font-size: 1.25rem; font-weight: 700; color: #1d2327; }
        .header h1 span { color: #2271b1; }
        .description {
            font-size: .9375rem;
            color: #3c434a;
            text-align: center;
            margin-bottom: 1.75rem;
            line-height: 1.6;
        }
        .description strong { color: #1d2327; }
        .scope-list {
            background: #f6f7f7;
            border-radius: 4px;
            padding: .75rem 1rem;
            margin-bottom: 1.75rem;
            font-size: .875rem;
            color: #3c434a;
        }
        .scope-list ul { list-style: none; padding-left: 0; }
        .scope-list li::before { content: '✓ '; color: #00a32a; font-weight: 700; }
        .actions { display: flex; gap: .75rem; }
        .btn {
            flex: 1;
            padding: .625rem 1rem;
            border: none;
            border-radius: 4px;
            font-size: .9375rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .15s;
        }
        .btn:hover { opacity: .85; }
        .btn-allow { background: #2271b1; color: #fff; }
        .btn-deny  { background: #fff; color: #646970; border: 1px solid #c3c4c7; }
        .footer {
            margin-top: 1.25rem;
            font-size: .8125rem;
            color: #646970;
            text-align: center;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="header">
            <span class="brain">🧠</span>
            <h1>{$lbl_heading}</h1>
        </div>

        <p class="description">{$lbl_description}</p>

        <div class="scope-list">
            <ul>
                <li>{$lbl_scope_read}</li>
                <li>{$lbl_scope_create}</li>
                <li>{$lbl_scope_edit}</li>
            </ul>
        </div>

        <form method="post" action="{$submit_url}">
            {$vars['hidden_fields']}
            <div class="actions">
                <button type="submit" name="allow" value="1" class="btn btn-allow">{$lbl_allow}</button>
                <button type="submit" name="deny"  value="1" class="btn btn-deny">{$lbl_deny}</button>
            </div>
        </form>

        <p class="footer">{$lbl_signed_in}</p>
    </div>
</body>
</html>
HTML;
	}

	/**
	 * Build a string of hidden HTML input fields.
	 *
	 * @param array<string, string> $fields Key-value pairs to render as hidden inputs.
	 */
	private function build_hidden_fields( array $fields ): string {
		$html = '';
		foreach ( $fields as $name => $value ) {
			$html .= sprintf(
				'<input type="hidden" name="%s" value="%s">',
				esc_attr( $name ),
				esc_attr( $value )
			);
		}
		return $html;
	}
}
