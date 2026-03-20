<?php
/**
 * Access token repository — reads/writes pressocampus_oauth_tokens (type='access').
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;

class AccessTokenEntity implements AccessTokenEntityInterface {
	use EntityTrait;
	use AccessTokenTrait;
	use TokenEntityTrait;
}

class WPAccessTokenRepository implements AccessTokenRepositoryInterface {

	public function getNewToken(
		ClientEntityInterface $client,
		array $scopes,
		string|null $userId = null
	): AccessTokenEntityInterface {
		$token = new AccessTokenEntity();
		$token->setClient( $client );
		$token->setUserIdentifier( $userId );

		foreach ( $scopes as $scope ) {
			$token->addScope( $scope );
		}

		return $token;
	}

	public function persistNewAccessToken( AccessTokenEntityInterface $accessTokenEntity ): void {
		global $wpdb;

		$scopes = array_map(
			fn( $s ) => $s->getIdentifier(),
			$accessTokenEntity->getScopes()
		);

		$wpdb->insert(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array(
				'id'         => $accessTokenEntity->getIdentifier(),
				'type'       => 'access',
				'client_id'  => $accessTokenEntity->getClient()->getIdentifier(),
				'user_id'    => (int) $accessTokenEntity->getUserIdentifier(),
				'scopes'     => implode( ' ', $scopes ),
				'revoked'    => 0,
				'expires_at' => $accessTokenEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function revokeAccessToken( string $tokenId ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array( 'revoked' => 1 ),
			array(
				'id'   => $tokenId,
				'type' => 'access',
			),
			array( '%d' ),
			array( '%s', '%s' )
		);
	}

	public function isAccessTokenRevoked( string $tokenId ): bool {
		global $wpdb;

		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT revoked FROM {$wpdb->prefix}pressocampus_oauth_tokens WHERE id = %s LIMIT 1",
				$tokenId
			)
		);

		// Treat a missing token as revoked.
		if ( $revoked === null ) {
			return true;
		}

		return (bool) $revoked;
	}
}
