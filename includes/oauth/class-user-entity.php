<?php
/**
 * Minimal user entity required by AuthCodeGrant::completeAuthorizationRequest.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\UserEntityInterface;

/** Thin wrapper mapping a WordPress user ID to a league/oauth2-server UserEntityInterface. */
class UserEntity implements UserEntityInterface {
	use EntityTrait;

	/**
	 * Create a UserEntity from a WordPress user ID.
	 *
	 * @param int $user_id WordPress user ID.
	 */
	public static function from_wp_user_id( int $user_id ): self {
		$entity = new self();
		$entity->setIdentifier( (string) $user_id );
		return $entity;
	}
}
