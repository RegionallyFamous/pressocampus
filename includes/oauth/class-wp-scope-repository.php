<?php
/**
 * Scope repository — only the single 'pressocampus:memory' scope is valid.
 *
 * @package Pressocampus\OAuth
 * @license GPL-2.0-or-later
 */

namespace Pressocampus\OAuth;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\ScopeTrait;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;

class ScopeEntity implements ScopeEntityInterface {
	use EntityTrait;
	use ScopeTrait;
}

class WPScopeRepository implements ScopeRepositoryInterface {

	public function getScopeEntityByIdentifier( mixed $identifier ): ?ScopeEntityInterface {
		if ( $identifier !== PRESSOCAMPUS_SCOPE ) {
			return null;
		}

		$scope = new ScopeEntity();
		$scope->setIdentifier( $identifier );
		return $scope;
	}

	public function finalizeScopes(
		array $scopes,
		mixed $grantType,
		ClientEntityInterface $client,
		mixed $userIdentifier = null
	): array {
		return array_values(
			array_filter( $scopes, fn( $s ) => $s->getIdentifier() === PRESSOCAMPUS_SCOPE )
		);
	}
}
