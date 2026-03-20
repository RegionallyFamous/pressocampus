<?php
/**
 * PHPUnit tests for Pressocampus MCP dispatcher behavior.
 *
 * These tests exercise the core tool semantics (remember, forget, update,
 * soul management, search, expiry) through a thin in-process dispatcher
 * that mirrors what MCPEndpoint will expose.  When class-mcp-endpoint.php
 * is implemented in Phase 2 these tests can be ported to call it directly.
 *
 * @package Pressocampus
 */

namespace Pressocampus\Tests;

use Pressocampus\CPT;
use Pressocampus\Soul;
use Pressocampus\ResourceIndex;
use Pressocampus\Plugin;

// ---------------------------------------------------------------------------
// Inline dispatcher — thin wrapper over the Phase 1 business-logic classes.
// ---------------------------------------------------------------------------

if ( ! class_exists( 'Pressocampus\Tests\Pressocampus_Test_Dispatcher' ) ) :

/**
 * Replicates the tool semantics that MCPEndpoint will expose, without going
 * through the REST / OAuth layers.  Auth is bypassed — callers pass user_id
 * explicitly.
 */
class Pressocampus_Test_Dispatcher {

	public function __construct(
		private CPT           $cpt,
		private Soul          $soul,
		private ResourceIndex $index
	) {}

	// -------------------------------------------------------------------------
	// tool_remember
	// -------------------------------------------------------------------------

	/**
	 * Store a new memory.
	 *
	 * Returns:
	 *   On success:        ['uri', 'name', 'post_id', 'etag']
	 *   On duplicate:      ['possible_duplicate' => true, 'existing_uri', 'message']
	 *   On contradiction:  same as success + ['possible_contradiction' => true, 'similar_memory']
	 *   On error:          ['isError' => true, 'code', 'message']
	 */
	public function tool_remember( int $user_id, array $params ): array {
		$content    = trim( $params['content'] ?? '' );
		$name       = trim( $params['name']    ?? '' );
		$group      = trim( $params['group']   ?? '' );
		$priority   = $params['priority']   ?? 'normal';
		$confidence = $params['confidence'] ?? 'medium';
		$related    = (array) ( $params['related'] ?? [] );

		if ( $content === '' ) {
			return [ 'isError' => true, 'code' => 'missing_content', 'message' => 'Content is required.' ];
		}

		$host = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$hash = md5( $content );

		// 1. Duplicate check — same content hash already stored for this user.
		global $wpdb;
		$dup_post_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id
				   FROM {$wpdb->prefix}pressocampus_resource_index
				  WHERE content_hash = %s
				    AND user_id      = %d
				  LIMIT 1",
				$hash,
				$user_id
			)
		);

		if ( $dup_post_id > 0 ) {
			$dup_uri = (string) get_post_meta( $dup_post_id, '_pressocampus_uri', true );
			return [
				'possible_duplicate' => true,
				'existing_uri'       => $dup_uri,
				'message'            => 'Identical content already stored.',
			];
		}

		// 2. Contradiction check — existing memory with overlapping subject words
		//    but different content.
		$contradiction_uri = $this->detect_contradiction( $content, $user_id, $hash );

		// 3. Create the post.
		$title   = $name !== '' ? $name : wp_trim_words( $content, 10, '' );
		$uri     = CPT::generate_uri( $host );
		$post_id = wp_insert_post( [
			'post_type'    => PRESSOCAMPUS_CPT,
			'post_status'  => 'publish',
			'post_author'  => $user_id,
			'post_title'   => $title,
			'post_content' => $content,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return [ 'isError' => true, 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ];
		}

		update_post_meta( $post_id, '_pressocampus_uri',                $uri );
		update_post_meta( $post_id, '_pressocampus_mime_type',           'text/markdown' );
		update_post_meta( $post_id, '_pressocampus_annotation_priority', $priority );
		update_post_meta( $post_id, '_pressocampus_confidence',          $confidence );
		update_post_meta( $post_id, '_pressocampus_schema_version',      '1.0' );

		if ( ! empty( $related ) ) {
			update_post_meta( $post_id, '_pressocampus_related', implode( ',', $related ) );
		}

		if ( $group !== '' ) {
			wp_set_post_terms( $post_id, [ $group ], PRESSOCAMPUS_TAXONOMY );
		}

		$this->index->upsert( $post_id, $uri, $user_id, $content );

		$result = [
			'uri'     => $uri,
			'name'    => $title,
			'post_id' => $post_id,
			'etag'    => $hash,
		];

		if ( $contradiction_uri !== null ) {
			$result['possible_contradiction'] = true;
			$result['similar_memory']         = $contradiction_uri;
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// tool_forget
	// -------------------------------------------------------------------------

	/**
	 * Delete a memory by URI.
	 *
	 * Returns:
	 *   On success: ['success' => true, 'uri']
	 *   On error:   ['isError' => true, 'code', 'message']
	 */
	public function tool_forget( int $user_id, array $params ): array {
		$uri  = (string) ( $params['uri'] ?? '' );
		$host = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		if ( Soul::is_protected( $uri, $host ) ) {
			return [
				'isError' => true,
				'code'    => 'soul_protected',
				'message' => 'Cannot delete a protected memory (soul or index).',
			];
		}

		$post = $this->cpt->get_post_by_uri( $uri, $user_id );
		if ( $post === null ) {
			return [ 'isError' => true, 'code' => 'not_found', 'message' => 'Memory not found.' ];
		}

		// Remove this URI from _pressocampus_related on all referencing posts.
		global $wpdb;
		$linked_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
				   FROM {$wpdb->postmeta}
				  WHERE meta_key   = '_pressocampus_related'
				    AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $uri ) . '%'
			)
		) ?: [];

		foreach ( $linked_ids as $pid ) {
			$meta  = (string) get_post_meta( (int) $pid, '_pressocampus_related', true );
			$parts = array_filter(
				array_map( 'trim', explode( ',', $meta ) ),
				fn( $u ) => $u !== '' && $u !== $uri
			);
			update_post_meta( (int) $pid, '_pressocampus_related', implode( ',', array_values( $parts ) ) );
		}

		$this->index->delete_by_post_id( $post->ID );
		wp_delete_post( $post->ID, true );

		return [ 'success' => true, 'uri' => $uri ];
	}

	// -------------------------------------------------------------------------
	// tool_update_memory
	// -------------------------------------------------------------------------

	/**
	 * Replace the content of an existing memory (ETag-protected).
	 *
	 * Returns:
	 *   On success:      ['uri', 'etag']
	 *   On ETag mismatch: ['isError' => true, 'code' => 'etag_conflict', ...]
	 *   On not found:    ['isError' => true, 'code' => 'not_found', ...]
	 */
	public function tool_update_memory( int $user_id, array $params ): array {
		$uri     = (string) ( $params['uri']     ?? '' );
		$content = (string) ( $params['content'] ?? '' );
		$etag    = isset( $params['etag'] ) ? (string) $params['etag'] : null;

		$post = $this->cpt->get_post_by_uri( $uri, $user_id );
		if ( $post === null ) {
			return [ 'isError' => true, 'code' => 'not_found', 'message' => 'Memory not found.' ];
		}

		if ( $etag !== null ) {
			$current = $this->index->get_content_hash( $post->ID );
			if ( $current === '' ) {
				$current = md5( CPT::get_raw_content( $post->ID ) );
			}
			if ( ! hash_equals( $current, $etag ) ) {
				return [
					'isError' => true,
					'code'    => 'etag_conflict',
					'message' => 'Memory has been modified since you last read it. Fetch the current version and retry.',
				];
			}
		}

		wp_update_post( [ 'ID' => $post->ID, 'post_content' => $content ] );
		$this->index->upsert( $post->ID, $uri, $user_id, $content );

		return [ 'uri' => $uri, 'etag' => md5( $content ) ];
	}

	// -------------------------------------------------------------------------
	// tool_update_soul
	// -------------------------------------------------------------------------

	/**
	 * Replace the soul content (delegates to Soul::update).
	 *
	 * Returns same shape as Soul::update().
	 */
	public function tool_update_soul( int $user_id, array $params ): array {
		$content = (string) ( $params['content'] ?? '' );
		$etag    = isset( $params['etag'] ) ? (string) $params['etag'] : null;
		$host    = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		return $this->soul->update( $user_id, $content, $host, $etag );
	}

	// -------------------------------------------------------------------------
	// resources_list
	// -------------------------------------------------------------------------

	/**
	 * List all published memories for a user, sorted by priority.
	 *
	 * @return array<array{uri: string, name: string, priority: string, confidence: string, updated_at: string}>
	 */
	public function resources_list( int $user_id, array $params = [] ): array {
		$group = $params['group'] ?? null;

		$q_args = [
			'post_type'      => PRESSOCAMPUS_CPT,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'meta_query'     => [
				[
					'key'     => '_pressocampus_uri',
					'compare' => 'EXISTS',
				],
			],
		];

		if ( $group !== null ) {
			$q_args['tax_query'] = [
				[
					'taxonomy' => PRESSOCAMPUS_TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $group ),
				],
			];
		}

		$q       = new \WP_Query( $q_args );
		$results = [];

		foreach ( $q->posts as $post ) {
			$results[] = [
				'uri'        => (string) get_post_meta( $post->ID, '_pressocampus_uri', true ),
				'name'       => $post->post_title,
				'priority'   => (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' ),
				'confidence' => (string) ( get_post_meta( $post->ID, '_pressocampus_confidence', true ) ?: 'medium' ),
				'updated_at' => $post->post_modified,
			];
		}

		usort( $results, static function ( array $a, array $b ): int {
			$pa = CPT::priority_to_float( $a['priority'] );
			$pb = CPT::priority_to_float( $b['priority'] );
			if ( $pa !== $pb ) {
				return $pb <=> $pa;
			}
			return strcmp( $b['updated_at'], $a['updated_at'] );
		} );

		return $results;
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	/**
	 * Detect a possible contradiction: an existing memory that shares key words
	 * with $content but has different content.
	 *
	 * Returns the URI of the conflicting memory, or null.
	 */
	private function detect_contradiction( string $content, int $user_id, string $content_hash ): ?string {
		static $stopwords = [
			'that', 'this', 'with', 'from', 'have', 'been', 'they', 'will', 'your',
			'about', 'which', 'more', 'when', 'were', 'also', 'into', 'then', 'some',
			'them', 'like', 'does', 'gets', 'makes', 'takes', 'very', 'just', 'even',
			'here', 'there', 'what', 'where', 'would', 'could', 'should', 'their',
		];

		$words = preg_split( '/\s+/', strtolower( preg_replace( '/[^\w\s]/', '', $content ) ) );
		$words = array_values(
			array_unique(
				array_filter(
					(array) $words,
					fn( $w ) => strlen( $w ) >= 4 && ! in_array( $w, $stopwords, true )
				)
			)
		);

		// Check only the first three significant words to keep queries cheap.
		$key_words = array_slice( $words, 0, 3 );
		if ( empty( $key_words ) ) {
			return null;
		}

		foreach ( $key_words as $word ) {
			$results = $this->index->search( $word, $user_id, null, 5 );
			foreach ( $results as $r ) {
				$stored_hash = $this->index->get_content_hash( (int) $r['post_id'] );
				// Different hash means a different (possibly contradictory) memory.
				if ( $stored_hash !== '' && $stored_hash !== $content_hash ) {
					return (string) $r['uri'];
				}
			}
		}

		return null;
	}
}

endif; // class_exists

// ---------------------------------------------------------------------------
// Test class
// ---------------------------------------------------------------------------

/**
 * @covers \Pressocampus\CPT
 * @covers \Pressocampus\Soul
 * @covers \Pressocampus\ResourceIndex
 */
class MCPDispatcherTest extends TestCase {

	private Pressocampus_Test_Dispatcher $dispatcher;
	private ResourceIndex                $index;
	private Soul                         $soul;
	private CPT                          $cpt;
	private int                          $user_id;

	protected function set_up(): void {
		parent::set_up();

		$plugin       = Plugin::get_instance();
		$this->index  = $plugin->get_resource_index();
		$this->soul   = $plugin->get_soul();
		$this->cpt    = $plugin->get_cpt();
		$this->user_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $this->user_id );

		$this->dispatcher = new Pressocampus_Test_Dispatcher( $this->cpt, $this->soul, $this->index );
	}

	// =========================================================================
	// 1. tool_remember creates a memory
	// =========================================================================

	public function test_remember_creates_memory(): void {
		$host   = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$result = $this->dispatcher->tool_remember( $this->user_id, [
			'content'    => 'Nick lives in Phoenix, AZ.',
			'name'       => 'Location',
			'priority'   => 'important',
			'confidence' => 'high',
		] );

		// URI format: pressocampus://{host}/memory/{uuid4}
		$this->assertArrayHasKey( 'uri', $result );
		$this->assertMatchesRegularExpression(
			'#^pressocampus://' . preg_quote( $host, '#' ) . '/memory/[0-9a-f\-]{36}$#',
			$result['uri']
		);

		$post_id = $result['post_id'];
		$this->assertGreaterThan( 0, $post_id );

		// Meta saved.
		$this->assertSame( $result['uri'], get_post_meta( $post_id, '_pressocampus_uri', true ) );
		$this->assertSame( 'text/markdown', get_post_meta( $post_id, '_pressocampus_mime_type', true ) );
		$this->assertSame( 'important', get_post_meta( $post_id, '_pressocampus_annotation_priority', true ) );
		$this->assertSame( 'high', get_post_meta( $post_id, '_pressocampus_confidence', true ) );

		// Index upserted.
		$row = $this->index->get_by_uri( $result['uri'] );
		$this->assertNotNull( $row );
		$this->assertSame( $this->user_id, (int) $row['user_id'] );
		$this->assertSame( (string) $post_id, (string) $row['post_id'] );
		$this->assertSame( md5( 'Nick lives in Phoenix, AZ.' ), $row['content_hash'] );
	}

	// =========================================================================
	// 2. tool_remember detects duplicate content
	// =========================================================================

	public function test_remember_detects_duplicate(): void {
		$content = 'Nick is a software developer based in Phoenix.';

		$first = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => $content ] );
		$this->assertArrayNotHasKey( 'possible_duplicate', $first, 'First call should not flag a duplicate.' );
		$this->assertArrayHasKey( 'uri', $first );

		$second = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => $content ] );
		$this->assertArrayHasKey( 'possible_duplicate', $second );
		$this->assertTrue( (bool) $second['possible_duplicate'] );
		$this->assertSame( $first['uri'], $second['existing_uri'] );

		// Confirm no second post was created.
		$q = new \WP_Query( [
			'post_type'      => PRESSOCAMPUS_CPT,
			'post_status'    => 'publish',
			'author'         => $this->user_id,
			'posts_per_page' => -1,
			'no_found_rows'  => true,
		] );
		$this->assertCount( 1, $q->posts, 'Duplicate check must not create a second post.' );
	}

	// =========================================================================
	// 3. tool_remember detects contradiction
	// =========================================================================

	public function test_remember_detects_contradiction(): void {
		// Store the first memory.
		$first = $this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Nick likes coffee',
			'name'    => 'Coffee preference',
		] );
		$this->assertArrayHasKey( 'uri', $first );
		$this->assertArrayNotHasKey( 'possible_contradiction', $first );

		// Store a contradictory memory — same subject ("nick"), different predicate.
		$second = $this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Nick prefers tea',
			'name'    => 'Tea preference',
		] );

		$this->assertArrayHasKey( 'possible_contradiction', $second, 'Expected possible_contradiction flag on the second call.' );
		$this->assertTrue( (bool) $second['possible_contradiction'] );

		// The new memory should still have been saved.
		$this->assertArrayHasKey( 'uri', $second );
		$this->assertNotSame( $first['uri'], $second['uri'] );
	}

	// =========================================================================
	// 4. tool_forget deletes a memory
	// =========================================================================

	public function test_forget_deletes_memory(): void {
		$result  = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'Temporary fact.' ] );
		$uri     = $result['uri'];
		$post_id = $result['post_id'];

		$forget = $this->dispatcher->tool_forget( $this->user_id, [ 'uri' => $uri ] );
		$this->assertTrue( $forget['success'] );

		// Post hard-deleted.
		$this->assertNull( get_post( $post_id ) );

		// Index entry removed.
		$this->assertNull( $this->index->get_by_uri( $uri ) );
	}

	// =========================================================================
	// 5. tool_forget refuses to delete the soul
	// =========================================================================

	public function test_forget_protects_soul(): void {
		$host     = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$soul_uri = Soul::get_uri( $host );

		$result = $this->dispatcher->tool_forget( $this->user_id, [ 'uri' => $soul_uri ] );

		$this->assertTrue( (bool) ( $result['isError'] ?? false ) );
		$this->assertSame( 'soul_protected', $result['code'] );
	}

	// =========================================================================
	// 6. tool_forget refuses to delete the index
	// =========================================================================

	public function test_forget_protects_index(): void {
		$host      = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$index_uri = Soul::get_index_uri( $host );

		$result = $this->dispatcher->tool_forget( $this->user_id, [ 'uri' => $index_uri ] );

		$this->assertTrue( (bool) ( $result['isError'] ?? false ) );
		$this->assertSame( 'soul_protected', $result['code'] );
	}

	// =========================================================================
	// 7. tool_update_memory rejects a stale ETag
	// =========================================================================

	public function test_update_memory_etag_conflict(): void {
		$result = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'Original content.' ] );
		$uri    = $result['uri'];

		$update = $this->dispatcher->tool_update_memory( $this->user_id, [
			'uri'     => $uri,
			'content' => 'New content.',
			'etag'    => 'this-is-a-stale-etag-that-will-never-match',
		] );

		$this->assertTrue( (bool) ( $update['isError'] ?? false ) );
		$this->assertSame( 'etag_conflict', $update['code'] );

		// Original content must be unchanged.
		$this->assertSame( 'Original content.', CPT::get_raw_content( $result['post_id'] ) );
	}

	// =========================================================================
	// 8. tool_update_memory succeeds with the correct ETag
	// =========================================================================

	public function test_update_memory_succeeds_with_correct_etag(): void {
		$content = 'Original content.';
		$result  = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => $content ] );
		$uri     = $result['uri'];
		$post_id = $result['post_id'];

		$etag = $this->index->get_content_hash( $post_id );
		$this->assertNotEmpty( $etag, 'Index should have stored a content hash.' );
		$this->assertSame( md5( $content ), $etag );

		$new_content = 'Updated content.';
		$update = $this->dispatcher->tool_update_memory( $this->user_id, [
			'uri'     => $uri,
			'content' => $new_content,
			'etag'    => $etag,
		] );

		$this->assertArrayNotHasKey( 'isError', $update );
		$this->assertSame( $uri, $update['uri'] );
		$this->assertSame( md5( $new_content ), $update['etag'] );
		$this->assertSame( $new_content, CPT::get_raw_content( $post_id ) );
	}

	// =========================================================================
	// 9. Deleting a memory rewrites _pressocampus_related on other posts
	// =========================================================================

	public function test_related_pointer_rewrite_on_delete(): void {
		$a = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'Memory A.' ] );
		$b = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'Memory B.' ] );
		$c = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'Memory C.' ] );

		$uri_c = $c['uri'];

		// Point A and B at C.
		update_post_meta( $a['post_id'], '_pressocampus_related', $uri_c );
		update_post_meta( $b['post_id'], '_pressocampus_related', $uri_c );

		// Delete C.
		$this->dispatcher->tool_forget( $this->user_id, [ 'uri' => $uri_c ] );

		// C should be gone from A and B's related meta.
		$rel_a = (string) get_post_meta( $a['post_id'], '_pressocampus_related', true );
		$rel_b = (string) get_post_meta( $b['post_id'], '_pressocampus_related', true );

		$this->assertStringNotContainsString( $uri_c, $rel_a, 'URI C must be removed from A.' );
		$this->assertStringNotContainsString( $uri_c, $rel_b, 'URI C must be removed from B.' );
	}

	// =========================================================================
	// 10. Per-user scoping
	// =========================================================================

	public function test_per_user_scoping(): void {
		$user2 = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		// Create a memory as user 1.
		$result = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'User 1 private fact.' ] );
		$uri    = $result['uri'];

		// User 2 cannot read it via get_post_by_uri.
		$post = $this->cpt->get_post_by_uri( $uri, $user2 );
		$this->assertNull( $post, 'User 2 must not be able to retrieve user 1 memory.' );

		// User 2 cannot forget it.
		$forget = $this->dispatcher->tool_forget( $user2, [ 'uri' => $uri ] );
		$this->assertTrue( (bool) ( $forget['isError'] ?? false ) );
		$this->assertSame( 'not_found', $forget['code'] );

		// Memory is still accessible by user 1.
		$this->assertNotNull( $this->cpt->get_post_by_uri( $uri, $this->user_id ) );
	}

	// =========================================================================
	// 11. Soul is auto-created on first initialize
	// =========================================================================

	public function test_soul_created_on_first_initialize(): void {
		$fresh_user = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		$host       = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		// Brand-new user has no soul.
		$this->assertNull( $this->soul->get_post( $fresh_user ), 'No soul should exist before first initialize.' );

		// get_snapshot() auto-creates the soul.
		$snapshot = $this->soul->get_snapshot( $fresh_user );

		$this->assertNotEmpty( $snapshot['snapshot'] );
		$this->assertNotEmpty( $snapshot['etag'] );
		$this->assertSame( 'empty', $snapshot['status'] );
		$this->assertStringContainsString( 'Status: empty', $snapshot['snapshot'] );

		// Soul post now exists with the correct URI.
		$soul_post = $this->soul->get_post( $fresh_user );
		$this->assertInstanceOf( \WP_Post::class, $soul_post );
		$this->assertSame(
			Soul::get_uri( $host ),
			get_post_meta( $soul_post->ID, '_pressocampus_uri', true )
		);
	}

	// =========================================================================
	// 12. Updating the soul strips "Status: empty"
	// =========================================================================

	public function test_update_soul_removes_empty_status(): void {
		$host = (string) ( wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		// Bootstrap soul (starts with Status: empty).
		$snapshot = $this->soul->get_snapshot( $this->user_id );
		$this->assertSame( 'empty', $snapshot['status'] );

		$new_content = "# My Soul\n\n## Who I Am\nNick, software developer from Phoenix.";

		$update = $this->dispatcher->tool_update_soul( $this->user_id, [
			'content' => $new_content,
		] );

		$this->assertArrayNotHasKey( 'error', $update, 'update_soul should succeed.' );
		$this->assertArrayHasKey( 'uri', $update );
		$this->assertSame( Soul::get_uri( $host ), $update['uri'] );

		$soul_post = $this->soul->get_post( $this->user_id );
		$this->assertInstanceOf( \WP_Post::class, $soul_post );

		$saved = CPT::get_raw_content( $soul_post->ID );
		$this->assertStringNotContainsString( 'Status: empty', $saved );
		$this->assertStringContainsString( 'Nick, software developer from Phoenix.', $saved );
		$this->assertSame( 'complete', $this->soul->get_status( $this->user_id ) );
	}

	// =========================================================================
	// 13. resources_list returns memories sorted by priority
	// =========================================================================

	public function test_priority_sort_in_resources_list(): void {
		// Create three memories with distinct names and priorities.
		$this->dispatcher->tool_remember( $this->user_id, [
			'content'  => 'A normal-priority memory.',
			'name'     => 'Normal Memory',
			'priority' => 'normal',
		] );
		$this->dispatcher->tool_remember( $this->user_id, [
			'content'  => 'A critical-priority memory.',
			'name'     => 'Critical Memory',
			'priority' => 'critical',
		] );
		$this->dispatcher->tool_remember( $this->user_id, [
			'content'  => 'An important-priority memory.',
			'name'     => 'Important Memory',
			'priority' => 'important',
		] );

		$list = $this->dispatcher->resources_list( $this->user_id );

		// Extract only our three test memories.
		$filtered = array_values( array_filter(
			$list,
			fn( $m ) => in_array( $m['name'], [ 'Normal Memory', 'Critical Memory', 'Important Memory' ], true )
		) );

		$this->assertCount( 3, $filtered );
		$this->assertSame( 'critical',  $filtered[0]['priority'], 'critical should sort first' );
		$this->assertSame( 'important', $filtered[1]['priority'], 'important should sort second' );
		$this->assertSame( 'normal',    $filtered[2]['priority'], 'normal should sort last' );
	}

	// =========================================================================
	// 14. Expired memories transition to pressocampus_expired
	// =========================================================================

	public function test_pressocampus_expired_status(): void {
		$result  = $this->dispatcher->tool_remember( $this->user_id, [ 'content' => 'This memory has an expiry date.' ] );
		$post_id = $result['post_id'];

		// Set expiry to a date in the past.
		update_post_meta( $post_id, '_pressocampus_expires_at', '2000-01-01T00:00:00Z' );

		// Trigger the expiry cron callback.
		$this->cpt->expire_old_memories();

		$post = get_post( $post_id );
		$this->assertInstanceOf( \WP_Post::class, $post );
		$this->assertSame( 'pressocampus_expired', $post->post_status );
	}

	// =========================================================================
	// 15. search_memory returns keyword-matched results
	// =========================================================================

	public function test_search_memory_returns_results(): void {
		// Three memories that should match "Phoenix".
		$this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Nick visited Phoenix last spring.',
			'name'    => 'Phoenix visit',
		] );
		$this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'The Phoenix art museum is nearby.',
			'name'    => 'Phoenix museum',
		] );
		$this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Phoenix is the capital of Arizona.',
			'name'    => 'Phoenix capital',
		] );

		// Two memories that must NOT appear.
		$this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Project deadline is next Friday.',
			'name'    => 'Deadline note',
		] );
		$this->dispatcher->tool_remember( $this->user_id, [
			'content' => 'Meeting deadline extended by two days.',
			'name'    => 'Extended deadline',
		] );

		$results = $this->index->search( 'Phoenix', $this->user_id );

		// Exactly the three Phoenix memories.
		$this->assertCount( 3, $results );

		foreach ( $results as $r ) {
			$this->assertStringNotContainsString(
				'deadline',
				strtolower( $r['excerpt'] ),
				'Deadline memories must not appear in Phoenix search.'
			);
			$this->assertStringContainsString(
				'phoenix',
				strtolower( $r['excerpt'] ),
				'Each result must contain "phoenix".'
			);
		}
	}
}
