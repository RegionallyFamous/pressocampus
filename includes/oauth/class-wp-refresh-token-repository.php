<?php
/**
 * Refresh token repository — reads/writes pressocampus_oauth_tokens (type='refresh').
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\RefreshTokenTrait;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;

class RefreshTokenEntity implements RefreshTokenEntityInterface {
	use EntityTrait;
	use RefreshTokenTrait;
}

class WPRefreshTokenRepository implements RefreshTokenRepositoryInterface {

	public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
		return new RefreshTokenEntity();
	}

	public function persistNewRefreshToken( RefreshTokenEntityInterface $refreshTokenEntity ): void {
		global $wpdb;

		$access_token   = $refreshTokenEntity->getAccessToken();
		$access_user_id = (int) $access_token->getUserIdentifier();
		$access_scopes  = implode( ' ', array_map( fn( $s ) => $s->getIdentifier(), $access_token->getScopes() ) );
		$client_id      = $access_token->getClient()->getIdentifier();

		$wpdb->insert(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array(
				'id'         => $refreshTokenEntity->getIdentifier(),
				'type'       => 'refresh',
				'client_id'  => $client_id,
				'user_id'    => $access_user_id,
				'scopes'     => $access_scopes,
				'revoked'    => 0,
				'expires_at' => $refreshTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function revokeRefreshToken( string $tokenId ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array( 'revoked' => 1 ),
			array(
				'id'   => $tokenId,
				'type' => 'refresh',
			),
			array( '%d' ),
			array( '%s', '%s' )
		);
	}

	public function isRefreshTokenRevoked( string $tokenId ): bool {
		global $wpdb;

		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT revoked FROM {$wpdb->prefix}pressocampus_oauth_tokens WHERE id = %s AND type = 'refresh' LIMIT 1",
				$tokenId
			)
		);

		if ( $revoked === null ) {
			return true;
		}

		return (bool) $revoked;
	}
}
