<?php
/**
 * Client repository — reads/writes pressocampus_oauth_clients.
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;

class ClientEntity implements ClientEntityInterface {
	use EntityTrait;
	use ClientTrait;

	public function __construct(
		string $client_id,
		string $client_name,
		array $redirect_uris,
		bool $is_confidential,
	) {
		$this->setIdentifier( $client_id );
		$this->name = $client_name;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- required by league/oauth2-server ClientEntityInterface
		$this->redirectUri = $redirect_uris;
		// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- required by league/oauth2-server ClientEntityInterface
		$this->isConfidential = $is_confidential;
	}
}

class WPClientRepository implements ClientRepositoryInterface {

	private const ALLOWED_GRANTS = array( 'authorization_code', 'refresh_token' );

	public function validateClient(
		string $clientId,
		?string $clientSecret,
		?string $grantType
	): bool {
		global $wpdb;

		if ( $grantType !== null && ! in_array( $grantType, self::ALLOWED_GRANTS, true ) ) {
			return false;
		}

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT secret, is_confidential FROM {$wpdb->prefix}pressocampus_oauth_clients WHERE id = %s LIMIT 1",
				$clientId
			)
		);

		if ( $row === null ) {
			return false;
		}

		if ( (bool) $row->is_confidential ) {
			if ( $clientSecret === null ) {
				return false;
			}
			if ( ! hash_equals( $row->secret, $clientSecret ) ) {
				return false;
			}
		}

		// Record last activity.
		$wpdb->update(
			$wpdb->prefix . 'pressocampus_oauth_clients',
			array( 'last_used_at' => current_time( 'mysql', true ) ),
			array( 'id' => $clientId ),
			array( '%s' ),
			array( '%s' )
		);

		return true;
	}

	public function getClientEntity( string $clientId ): ?ClientEntityInterface {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name, redirect_uris, is_confidential FROM {$wpdb->prefix}pressocampus_oauth_clients WHERE id = %s LIMIT 1",
				$clientId
			)
		);

		if ( $row === null ) {
			return null;
		}

		$redirect_uris = json_decode( $row->redirect_uris, true );
		if ( ! is_array( $redirect_uris ) ) {
			$redirect_uris = array_filter( array( $row->redirect_uris ) );
		}

		return new ClientEntity(
			$row->id,
			$row->name,
			$redirect_uris,
			(bool) $row->is_confidential,
		);
	}

	/**
	 * Register a new OAuth client (RFC 7591 dynamic client registration).
	 *
	 * @param string $name          Human-readable client name.
	 * @param array  $redirect_uris Array of allowed redirect URIs.
	 * @param bool   $is_confidential Whether the client can keep a secret.
	 * @param int    $user_id        WordPress user ID that owns this client (0 = global).
	 *
	 * @return array{client_id: string, client_secret: string}
	 */
	public static function register(
		string $name,
		array $redirect_uris,
		bool $is_confidential = false,
		int $user_id = 0
	): array {
		global $wpdb;

		$client_id     = uniqid( 'prc_', true );
		$client_secret = wp_generate_password( 40, false );

		$wpdb->insert(
			$wpdb->prefix . 'pressocampus_oauth_clients',
			array(
				'id'              => $client_id,
				'name'            => $name,
				'secret'          => $client_secret,
				'redirect_uris'   => wp_json_encode( $redirect_uris ),
				'scopes'          => PRESSOCAMPUS_SCOPE,
				'is_confidential' => $is_confidential ? 1 : 0,
				'user_id'         => $user_id,
				'created_at'      => current_time( 'mysql', true ),
				'last_used_at'    => null,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return array(
			'client_id'     => $client_id,
			'client_secret' => $client_secret,
		);
	}
}
