<?php
/**
 * Auth code repository — reads/writes pressocampus_oauth_tokens (type='auth_code').
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Entities\Traits\AuthCodeTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;

class AuthCodeEntity implements AuthCodeEntityInterface {
	use EntityTrait;
	use TokenEntityTrait;
	use AuthCodeTrait;
}

class WPAuthCodeRepository implements AuthCodeRepositoryInterface {

	public function getNewAuthCode(): AuthCodeEntityInterface {
		return new AuthCodeEntity();
	}

	public function persistNewAuthCode( AuthCodeEntityInterface $authCodeEntity ): void {
		global $wpdb;

		$scopes = array_map(
			fn( $s ) => $s->getIdentifier(),
			$authCodeEntity->getScopes()
		);

		$wpdb->insert(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array(
				'id'         => $authCodeEntity->getIdentifier(),
				'type'       => 'auth_code',
				'client_id'  => $authCodeEntity->getClient()->getIdentifier(),
				'user_id'    => (int) $authCodeEntity->getUserIdentifier(),
				'scopes'     => implode( ' ', $scopes ),
				'revoked'    => 0,
				'expires_at' => $authCodeEntity->getExpiryDateTime()->format( 'Y-m-d H:i:s' ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function revokeAuthCode( string $codeId ): void {
		global $wpdb;

		$wpdb->update(
			$wpdb->prefix . 'pressocampus_oauth_tokens',
			array( 'revoked' => 1 ),
			array(
				'id'   => $codeId,
				'type' => 'auth_code',
			),
			array( '%d' ),
			array( '%s', '%s' )
		);
	}

	public function isAuthCodeRevoked( string $codeId ): bool {
		global $wpdb;

		$revoked = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT revoked FROM {$wpdb->prefix}pressocampus_oauth_tokens WHERE id = %s AND type = 'auth_code' LIMIT 1",
				$codeId
			)
		);

		if ( $revoked === null ) {
			return true;
		}

		return (bool) $revoked;
	}
}
