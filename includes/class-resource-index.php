<?php
/**
 * Manages the pressocampus_resource_index DB table.
 *
 * Schema (created by Installer):
 *   id             bigint unsigned AUTO_INCREMENT PRIMARY KEY
 *   post_id        bigint unsigned NOT NULL
 *   user_id        bigint unsigned NOT NULL
 *   uri            varchar(500) NOT NULL UNIQUE KEY(191)
 *   content_hash   varchar(32)  NOT NULL DEFAULT ''
 *   excerpt        text         NOT NULL DEFAULT ''
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ResourceIndex {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressocampus_resource_index';
	}

	/**
	 * Insert or update an index row for a post.
	 *
	 * Columns computed on write:
	 *   content_hash = md5( $content )
	 *   excerpt      = first 200 chars of stripped content
	 */
	public function upsert( int $post_id, string $uri, int $user_id, string $content ): void {
		global $wpdb;

		$hash = md5( $content );

		// Strip Markdown headings from the start so the excerpt covers substantive content
		// rather than repeating the title. Then extend to 500 chars for better FULLTEXT coverage.
		$stripped = wp_strip_all_tags( $content );
		$stripped = (string) preg_replace( '/^[#\s]+[^\n]*\n*/m', '', ltrim( $stripped ), 3 );
		$excerpt  = mb_substr( trim( $stripped ), 0, 500, 'UTF-8' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table()} (post_id, user_id, uri, content_hash, excerpt)
                 VALUES (%d, %d, %s, %s, %s)
                 ON DUPLICATE KEY UPDATE
                     post_id      = VALUES(post_id),
                     user_id      = VALUES(user_id),
                     content_hash = VALUES(content_hash),
                     excerpt      = VALUES(excerpt)",
				$post_id,
				$user_id,
				$uri,
				$hash,
				$excerpt
			)
		);

		// Invalidate cached memory count so the next get_memory_count() reflects the new row.
		wp_cache_delete( 'pc_memory_count_' . $user_id, 'pressocampus' );
	}

	public function delete_by_post_id( int $post_id ): void {
		global $wpdb;

		// Fetch user_id before deleting so we can invalidate the cached memory count.
		$user_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT user_id FROM {$this->table()} WHERE post_id = %d LIMIT 1",
				$post_id
			)
		);

		$wpdb->delete( $this->table(), array( 'post_id' => $post_id ), array( '%d' ) );

		if ( $user_id > 0 ) {
			wp_cache_delete( 'pc_memory_count_' . $user_id, 'pressocampus' );
		}
	}

	public function get_by_uri( string $uri ): ?array {
		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT post_id, user_id, content_hash, excerpt
                   FROM {$this->table()}
                  WHERE uri = %s
                  LIMIT 1",
				$uri
			),
			ARRAY_A
		);

		return $row ?: null;
	}

	public function get_content_hash( int $post_id ): string {
		global $wpdb;

		$hash = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT content_hash FROM {$this->table()} WHERE post_id = %d LIMIT 1",
				$post_id
			)
		);

		return (string) ( $hash ?? '' );
	}

	/**
	 * Search memories for a user.
	 *
	 * Runs two sub-searches and merges them:
	 *   1. WP_Query full-text search (post title / content)
	 *   2. LIKE search on the excerpt column of the index table
	 *
	 * Returns array of rows: [uri, post_id, name, excerpt, confidence, priority, updated_at]
	 * ordered by priority DESC, sorted by relevance.
	 */
	public function search( string $query, int $user_id, ?string $group = null, int $limit = 10 ): array {
		global $wpdb;

		$results = array();

		// WP_Query full-text search.
		$wp_query_args = array(
			's'              => $query,
			'post_type'      => PRESSOCAMPUS_CPT,
			'author'         => $user_id,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		);

		if ( $group !== null ) {
			$wp_query_args['tax_query'] = array(
				array(
					'taxonomy' => PRESSOCAMPUS_TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $group ),
				),
			);
		}

		$wp_q = new \WP_Query( $wp_query_args );

		if ( ! empty( $wp_q->posts ) ) {
			// Prime the postmeta cache so the get_post_meta() calls below
			// don't each fire a separate DB query (avoids N+1 per result).
			update_postmeta_cache( $wp_q->posts );

			$placeholders = implode( ',', array_fill( 0, count( $wp_q->posts ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
                    // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT i.post_id, i.uri, i.excerpt, i.content_hash,
                            p.post_title AS name,
                            p.post_modified AS updated_at
                       FROM {$this->table()} i
                       JOIN {$wpdb->posts} p ON p.ID = i.post_id
                      WHERE i.post_id IN ($placeholders)",
					...$wp_q->posts
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$priority   = get_post_meta( (int) $row['post_id'], '_pressocampus_annotation_priority', true ) ?: 'normal';
				$confidence = get_post_meta( (int) $row['post_id'], '_pressocampus_confidence', true ) ?: 'medium';

				$results[ (int) $row['post_id'] ] = array(
					'uri'        => $row['uri'],
					'post_id'    => (int) $row['post_id'],
					'name'       => $row['name'],
					'excerpt'    => $row['excerpt'],
					'confidence' => $confidence,
					'priority'   => $priority,
					'updated_at' => $row['updated_at'],
				);
			}
		}

		// Excerpt search against the index table.
		// Use FULLTEXT MATCH/AGAINST when the query is long enough for the InnoDB full-text
		// minimum word length (3 chars). Fall back to LIKE for very short queries.
		if ( mb_strlen( $query, 'UTF-8' ) >= 3 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$like_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.post_id, i.uri, i.excerpt, i.content_hash,
                            p.post_title AS name,
                            p.post_modified AS updated_at
                       FROM {$this->table()} i
                       JOIN {$wpdb->posts} p ON p.ID = i.post_id
                      WHERE i.user_id = %d
                        AND MATCH(i.excerpt) AGAINST(%s IN BOOLEAN MODE)
                        AND p.post_status = 'publish'
                      LIMIT %d",
					$user_id,
					$query,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$like_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT i.post_id, i.uri, i.excerpt, i.content_hash,
                            p.post_title AS name,
                            p.post_modified AS updated_at
                       FROM {$this->table()} i
                       JOIN {$wpdb->posts} p ON p.ID = i.post_id
                      WHERE i.user_id = %d
                        AND i.excerpt LIKE %s
                        AND p.post_status = 'publish'
                      LIMIT %d",
					$user_id,
					'%' . $wpdb->esc_like( $query ) . '%',
					$limit
				),
				ARRAY_A
			);
		}

		// Prime postmeta cache for LIKE results not already seen in the WP_Query pass.
		$new_like_ids = array_values(
			array_filter(
				array_map( fn( array $r ): int => (int) $r['post_id'], $like_rows ),
				fn( int $id ): bool => ! isset( $results[ $id ] )
			)
		);
		if ( ! empty( $new_like_ids ) ) {
			update_postmeta_cache( $new_like_ids );
		}

		foreach ( $like_rows as $row ) {
			$post_id = (int) $row['post_id'];
			if ( isset( $results[ $post_id ] ) ) {
				continue; // already present from WP_Query pass
			}

			$priority   = get_post_meta( $post_id, '_pressocampus_annotation_priority', true ) ?: 'normal';
			$confidence = get_post_meta( $post_id, '_pressocampus_confidence', true ) ?: 'medium';

			$results[ $post_id ] = array(
				'uri'        => $row['uri'],
				'post_id'    => $post_id,
				'name'       => $row['name'],
				'excerpt'    => $row['excerpt'],
				'confidence' => $confidence,
				'priority'   => $priority,
				'updated_at' => $row['updated_at'],
			);
		}

		// Sort by priority float descending, then by recency.
		usort(
			$results,
			static function ( array $a, array $b ): int {
				$pa = CPT::priority_to_float( $a['priority'] );
				$pb = CPT::priority_to_float( $b['priority'] );
				if ( $pa !== $pb ) {
					return $pb <=> $pa;
				}
				return strcmp( $b['updated_at'], $a['updated_at'] );
			}
		);

		return array_values( array_slice( $results, 0, $limit ) );
	}

	/**
	 * Find all post IDs whose _pressocampus_related meta references $uri.
	 *
	 * @return int[]
	 */
	public function get_reverse_links( string $uri ): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
                   FROM {$wpdb->postmeta}
                  WHERE meta_key   = '_pressocampus_related'
                    AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $uri ) . '%'
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Rewrite all occurrences of $old_uri to $new_uri inside
	 * _pressocampus_related meta values (comma-separated URI strings).
	 */
	public function rewrite_related_uri( string $old_uri, string $new_uri ): void {
		global $wpdb;

		$post_ids = $this->get_reverse_links( $old_uri );

		if ( empty( $post_ids ) ) {
			return;
		}

		// Batch-prime the postmeta cache so the get_post_meta() calls inside
		// the loop below don't each fire an individual DB query.
		update_postmeta_cache( $post_ids );

		foreach ( $post_ids as $post_id ) {
			$meta = (string) get_post_meta( $post_id, '_pressocampus_related', true );
			if ( $meta === '' ) {
				continue;
			}

			$parts   = array_map( 'trim', explode( ',', $meta ) );
			$updated = array_map(
				static fn( string $u ): string => $u === $old_uri ? $new_uri : $u,
				$parts
			);

			update_post_meta( $post_id, '_pressocampus_related', implode( ',', $updated ) );
		}
	}

	/**
	 * Mark the index as dirty for $user_id and schedule an async rebuild.
	 *
	 * The dirty transient lives for a full day so a low-traffic site that goes
	 * hours between page loads still rebuilds the index eventually.  The
	 * resources/list object-cache entry is also busted so the next list call
	 * serves fresh data.
	 */
	public function mark_dirty( int $user_id ): void {
		set_transient( 'pressocampus_index_dirty_' . $user_id, 1, DAY_IN_SECONDS );

		// Invalidate cached responses that depend on the memory set.
		wp_cache_delete( 'pc_resources_list_' . $user_id, 'pressocampus' );
		wp_cache_delete( 'pc_user_groups_' . $user_id, 'pressocampus' );

		// Schedule an async rebuild via WP-Cron (fires on the next page load).
		// wp_next_scheduled() deduplicates: only one rebuild event per user at a time.
		if ( ! wp_next_scheduled( 'pressocampus_rebuild_index', array( $user_id ) ) ) {
			wp_schedule_single_event( time(), 'pressocampus_rebuild_index', array( $user_id ) );
		}
	}

	/**
	 * Rebuild the in-memory index post for $user_id if the dirty transient is set.
	 *
	 * Uses a short-lived rebuild-lock transient to prevent duplicate concurrent
	 * rebuilds (e.g. two requests both read the dirty flag before either clears
	 * it).  The dirty flag is only cleared after a confirmed successful rebuild.
	 *
	 * @param int    $user_id WordPress user ID.
	 * @param string $host    Site hostname for URI namespacing.
	 * @param Soul   $soul    Injected Soul instance (avoids hidden circular dependency).
	 */
	public function rebuild_if_dirty( int $user_id, string $host, Soul $soul ): void {
		if ( ! get_transient( 'pressocampus_index_dirty_' . $user_id ) ) {
			return;
		}

		global $wpdb;

		// Use a MySQL advisory lock so concurrent requests don't trigger duplicate rebuilds.
		$lock_name = 'pc_rebuild_' . $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		if ( ! $acquired ) {
			return;
		}

		try {
			$soul->rebuild_index( $user_id, $host );
			// Only clear the dirty flag after a successful rebuild.
			delete_transient( 'pressocampus_index_dirty_' . $user_id );
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Return an array of distinct group taxonomy slugs for a user.
	 *
	 * Cached for 5 minutes; invalidated by mark_dirty() on any write.
	 *
	 * @return string[]
	 */
	public function get_user_groups( int $user_id ): array {
		$cache_key = 'pc_user_groups_' . $user_id;
		$cached    = wp_cache_get( $cache_key, 'pressocampus' );
		if ( false !== $cached ) {
			return (array) $cached;
		}

		global $wpdb;

		// Single JOIN query avoids the two-step: get_user_post_ids() + get_terms(object_ids).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$slugs = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT t.slug
				   FROM {$wpdb->term_taxonomy} tt
				   JOIN {$wpdb->terms} t             ON t.term_id    = tt.term_id
				   JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id
				   JOIN {$this->table()} i            ON i.post_id   = tr.object_id
				  WHERE i.user_id    = %d
				    AND tt.taxonomy  = %s
				    AND tt.count     > 0
				  ORDER BY t.slug ASC",
				$user_id,
				PRESSOCAMPUS_TAXONOMY
			)
		);

		$result = is_array( $slugs ) ? $slugs : array();

		wp_cache_set( $cache_key, $result, 'pressocampus', 300 );

		return $result;
	}

	/**
	 * Total published memory count for $user_id, excluding the soul and index posts.
	 */
	public function get_memory_count( int $user_id ): int {
		global $wpdb;

		$cache_key = 'pc_memory_count_' . $user_id;
		$cached    = wp_cache_get( $cache_key, 'pressocampus' );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$host      = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';
		$soul_uri  = Soul::get_uri( $host );
		$index_uri = Soul::get_index_uri( $host );

		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
                   FROM {$this->table()} i
                   JOIN {$wpdb->posts} p ON p.ID = i.post_id
                  WHERE i.user_id   = %d
                    AND p.post_status = 'publish'
                    AND i.uri NOT IN (%s, %s)",
				$user_id,
				$soul_uri,
				$index_uri
			)
		);

		wp_cache_set( $cache_key, (int) $count, 'pressocampus', 60 );

		return (int) $count;
	}
}
