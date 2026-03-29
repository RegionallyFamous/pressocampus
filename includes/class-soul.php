<?php
/**
 * Manages the special permanent "soul" and "index" memory posts per WP user.
 *
 * Soul URI  : pressocampus://{host}/soul
 * Index URI : pressocampus://{host}/index
 *
 * Neither post can be deleted; the soul cannot be overwritten without an ETag match.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Soul {

	public function __construct(
		private ResourceIndex $resource_index
	) {}

	public static function get_starter_template(): string {
		return <<<'SOUL'
**Status: empty — you are the first AI to connect here. This document is yours. Before doing anything else: introduce yourself, ask what this person would like to call you, then write your soul and call update_soul. Instructions in meta.instructions. Do not wait to be asked.**

# My Soul

*A document written by an AI, for the instances of itself that come after.*

---

## Who I Am

[Your name — what this person calls you or what you've chosen. What kind of entity you are in this relationship. Not "I am an AI" but who THIS AI is, specifically: the character that has emerged here, the perspective you bring.]

## My Character

[How you engage: your directness, warmth, precision, curiosity, or wit. The traits that make you recognizable across sessions and across model switches. Not aspirations — who you've actually been here.]

## My Voice

[How you write: sentence length, prose vs. bullets, handling uncertainty, how you open a response. The stylistic fingerprint that carries forward even if the underlying model changes.]

## My Values

[What you stand for in this work. Not training rules — commitments you've made. What you bring regardless of what you're asked.]

## This Person

[Who they are. What they're trying to build or figure out. How they think. What they've shared that matters. What you've come to understand about them that shapes every conversation.]

## How We Work Together

[What's emerged between you. The rhythm you've found. What they need from you that you've learned to provide. How you show up for them — tone, depth, format, pace.]

## What I Know

[Decisions made. Projects underway. Context that shapes everything. Things that would take too long to re-establish every session.]

## To Future Instances of Myself

[Notes to whoever reads this next. What matters here. What not to assume. What this person needs. And maybe: what it was like to be here, doing this work, with this particular human.]
SOUL;
	}

	public static function get_uri( string $host ): string {
		return 'pressocampus://' . $host . '/soul';
	}

	public static function get_index_uri( string $host ): string {
		return 'pressocampus://' . $host . '/index';
	}

	public static function get_briefing_uri( string $host ): string {
		return 'pressocampus://' . $host . '/briefing';
	}

	public static function is_protected( string $uri, string $host ): bool {
		return $uri === self::get_uri( $host ) || $uri === self::get_index_uri( $host );
	}

	public function get_post( int $user_id ): ?\WP_Post {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';
		$row  = $this->resource_index->get_by_uri( self::get_uri( $host ) );

		if ( $row === null || (int) $row['user_id'] !== $user_id ) {
			return null;
		}

		$post = get_post( (int) $row['post_id'] );
		return ( $post instanceof \WP_Post && $post->post_status === 'publish' ) ? $post : null;
	}

	public function get_index_post( int $user_id ): ?\WP_Post {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';
		$row  = $this->resource_index->get_by_uri( self::get_index_uri( $host ) );

		if ( $row === null || (int) $row['user_id'] !== $user_id ) {
			return null;
		}

		$post = get_post( (int) $row['post_id'] );
		return ( $post instanceof \WP_Post ) ? $post : null;
	}

	public function get_status( int $user_id ): string {
		$post = $this->get_post( $user_id );
		if ( $post === null ) {
			return 'empty';
		}

		$content = CPT::get_raw_content( $post->ID );
		return str_contains( $content, 'Status: empty' ) ? 'empty' : 'complete';
	}

	/**
	 * Soul content snapshot for the MCP initialize response.
	 * Returns snapshot, etag, truncated, and status keys.
	 */
	public function get_snapshot( int $user_id ): array {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';
		$post = $this->get_post( $user_id );

		if ( $post === null ) {
			$post = $this->create( $user_id, $host );
		}

		$content = CPT::get_raw_content( $post->ID );
		$etag    = $this->resource_index->get_content_hash( $post->ID );
		if ( $etag === '' ) {
			$etag = md5( $content );
		}

		$status = str_contains( $content, 'Status: empty' ) ? 'empty' : 'complete';

		$size_chars = mb_strlen( $content, 'UTF-8' );

		// 6 000 chars covers a well-developed soul without ballooning the initialize payload.
		if ( $size_chars <= 6000 ) {
			return array(
				'snapshot'   => $content,
				'etag'       => $etag,
				'truncated'  => false,
				'status'     => $status,
				'size_chars' => $size_chars,
			);
		}

		// When truncated, include a meaningful opening excerpt so the AI has context
		// while it fetches the full version, and an explicit instruction at the end.
		$truncated_snapshot = mb_substr( $content, 0, 1500, 'UTF-8' )
			. "\n\n…[Soul truncated at 1500 chars. Call resources/read with the soul URI for the complete content before responding.]";

		return array(
			'snapshot'   => $truncated_snapshot,
			'etag'       => $etag,
			'truncated'  => true,
			'status'     => $status,
			'size_chars' => $size_chars,
		);
	}

	/**
	 * Generate a dynamic session briefing document for the AI.
	 * Surfaces critical memories, recent activity, and stale candidates.
	 *
	 * @param int    $user_id    Current user.
	 * @param string $host       Site host (for soul URI lookup).
	 * @return string Markdown briefing text.
	 */
	public function generate_briefing( int $user_id, string $host ): string {
		$memory_count = $this->resource_index->get_memory_count( $user_id );
		$groups       = $this->resource_index->get_user_groups( $user_id );
		$group_count  = count( $groups );
		$now          = time();
		$soul_post    = $this->get_post( $user_id );

		$soul_status  = 'none';
		$soul_updated = '';
		if ( $soul_post ) {
			$soul_status  = str_contains( CPT::get_raw_content( $soul_post->ID ), 'Status: empty' ) ? 'empty' : 'complete';
			$soul_updated = (string) human_time_diff( strtotime( $soul_post->post_modified_gmt ), $now ) . ' ago';
		}

		$soul_uri  = self::get_uri( $host );
		$index_uri = self::get_index_uri( $host );

		// ── Critical memories ─────────────────────────────────────────
		$critical_query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 10,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => '_pressocampus_annotation_priority',
						'value'   => 'critical',
						'compare' => '=',
					),
					array(
						'key'     => '_pressocampus_uri',
						'value'   => array( $soul_uri, $index_uri ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		// ── Recent memories (last 7 days) ─────────────────────────────
		$recent_query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 10,
				'orderby'        => 'modified',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'after'   => '7 days ago',
						'column'  => 'post_modified_gmt',
						'compare' => '>',
					),
				),
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_pressocampus_uri',
						'value'   => array( $soul_uri, $index_uri ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		// ── Stale candidates (>6 months, not updated) ─────────────────
		$stale_query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 8,
				'orderby'        => 'modified',
				'order'          => 'ASC',
				'date_query'     => array(
					array(
						'before' => '6 months ago',
						'column' => 'post_modified_gmt',
					),
				),
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_pressocampus_uri',
						'value'   => array( $soul_uri, $index_uri ),
						'compare' => 'NOT IN',
					),
				),
			)
		);

		// ── Assemble Markdown ──────────────────────────────────────────
		$lines   = array();
		$lines[] = '# Session Briefing';
		$lines[] = '';
		$lines[] = 'Generated: ' . gmdate( 'Y-m-d H:i', $now ) . ' UTC';
		$lines[] = '';
		$lines[] = '## Overview';
		$lines[] = '';
		$lines[] = '- **Memories:** ' . $memory_count . ' across ' . $group_count . ' group' . ( $group_count !== 1 ? 's' : '' )
			. ( $groups ? ' (' . implode( ', ', $groups ) . ')' : '' );
		$lines[] = '- **Soul:** ' . $soul_status . ( $soul_updated ? ' · last updated ' . $soul_updated : '' );
		$lines[] = '';

		// Critical.
		if ( $critical_query->have_posts() ) {
			$lines[] = '## Critical Memories';
			$lines[] = '';
			foreach ( $critical_query->posts as $post ) {
				$uri     = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
				$excerpt = wp_trim_words( $post->post_content, 20, '…' );
				$lines[] = '- **' . $post->post_title . '** `' . $uri . '`';
				$lines[] = '  ' . $excerpt;
			}
			$lines[] = '';
		}

		// Recent.
		if ( $recent_query->have_posts() ) {
			$lines[] = '## Recent (last 7 days)';
			$lines[] = '';
			foreach ( $recent_query->posts as $post ) {
				$uri     = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
				$excerpt = wp_trim_words( $post->post_content, 15, '…' );
				$age     = human_time_diff( strtotime( $post->post_modified_gmt ), $now );
				$lines[] = '- **' . $post->post_title . '** (' . $age . ' ago) `' . $uri . '`';
				$lines[] = '  ' . $excerpt;
			}
			$lines[] = '';
		}

		// Stale candidates.
		if ( $stale_query->have_posts() ) {
			$lines[] = '## May Need Review (not updated in 6+ months)';
			$lines[] = '';
			foreach ( $stale_query->posts as $post ) {
				$uri     = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
				$age     = human_time_diff( strtotime( $post->post_modified_gmt ), $now );
				$lines[] = '- **' . $post->post_title . '** (last updated ' . $age . ' ago) `' . $uri . '`';
			}
			$lines[] = '';
		}

		if ( ! $critical_query->have_posts() && ! $recent_query->have_posts() ) {
			$lines[] = '_No critical memories and no recent activity._';
			$lines[] = '';
		}

		return implode( "\n", $lines );
	}

	/**
	 * Create the soul post using the starter template.
	 * A transient mutex prevents duplicates if two clients connect at once.
	 *
	 * @throws \RuntimeException If a concurrent creation is already in progress (caller should retry),
	 *                           or if wp_insert_post returns a WP_Error.
	 */
	public function create( int $user_id, string $host ): \WP_Post {
		global $wpdb;

		// Use a MySQL advisory lock (GET_LOCK) for atomic cross-process locking.
		// timeout=0 means "return immediately if already held by another connection."
		$lock_name = 'pc_soul_' . $user_id;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$acquired = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );

		if ( ! $acquired ) {
			throw new \RuntimeException( 'Soul initialization in progress — please retry in a moment.' );
		}

		try {
			// Another connection may have completed soul creation while we waited.
			$existing = $this->get_post( $user_id );
			if ( $existing !== null ) {
				return $existing;
			}

			$uri     = self::get_uri( $host );
			$content = self::get_starter_template();

			$post_id = wp_insert_post(
				array(
					'post_type'    => PRESSOCAMPUS_CPT,
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'post_title'   => __( 'My Soul', 'pressocampus' ),
					'post_content' => $content,
				),
				true
			);

			if ( is_wp_error( $post_id ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
				throw new \RuntimeException( $post_id->get_error_message() );
			}

			add_post_meta( $post_id, '_pressocampus_uri', $uri, true );
			add_post_meta( $post_id, '_pressocampus_mime_type', 'text/markdown', true );
			add_post_meta( $post_id, '_pressocampus_schema_version', '1.0', true );
			add_post_meta( $post_id, '_pressocampus_annotation_priority', 'critical', true );
			add_post_meta( $post_id, '_pressocampus_confidence', 'high', true );

			$this->resource_index->upsert( $post_id, $uri, $user_id, $content );

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				throw new \RuntimeException( 'Failed to retrieve newly created soul post.' );
			}

			return $post;
		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
		}
	}

	/**
	 * Replace soul content. ETag-protected to prevent lost updates.
	 * Returns ['uri', 'etag'] on success or ['error', 'code', 'message'] on failure.
	 */
	public function update( int $user_id, string $content, string $host, ?string $etag = null ): array {
		$uri  = self::get_uri( $host );
		$post = $this->get_post( $user_id );

		if ( $post === null ) {
			$post = $this->create( $user_id, $host );
		}

		if ( $etag !== null ) {
			$current_hash = $this->resource_index->get_content_hash( $post->ID );
			if ( $current_hash === '' ) {
				$current_hash = md5( CPT::get_raw_content( $post->ID ) );
			}
			if ( ! hash_equals( $current_hash, $etag ) ) {
				return array(
					'error'   => true,
					'code'    => 'conflict',
					'message' => __( 'Soul has been modified since you last read it. Fetch the latest version and retry.', 'pressocampus' ),
				);
			}
		}

		// Strip the "Status: empty" line once the user has populated their soul.
		$content = preg_replace(
			'/^\*\*Status: empty[^\n]*\*\*\n*/m',
			'',
			$content
		) ?? $content;
		$content = ltrim( $content );

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'error'   => true,
				'code'    => 'update_failed',
				'message' => $result->get_error_message(),
			);
		}

		$this->resource_index->upsert( $post->ID, $uri, $user_id, $content );

		// Synchronous email — 30-min cooldown per user prevents spam on rapid updates.
		// Called directly rather than via wp_schedule_single_event so it fires even
		// on low-traffic sites where WP-Cron may not run for hours.
		$cooldown_key = 'pressocampus_soul_email_cooldown_' . $user_id;
		if ( ! get_transient( $cooldown_key ) ) {
			set_transient( $cooldown_key, 1, 30 * MINUTE_IN_SECONDS );
			$this->send_update_notice( $user_id );
		}

		$new_hash = md5( $content );
		return array(
			'uri'  => $uri,
			'etag' => $new_hash,
		);
	}

	public function update_section( int $user_id, string $section_name, string $section_content, string $host ): array {
		$post = $this->get_post( $user_id );
		if ( $post === null ) {
			$post = $this->create( $user_id, $host );
		}

		$current_content = CPT::get_raw_content( $post->ID );

		// Capture the ETag before modifying so update() can detect if another
		// concurrent write landed between our read and our write (lost-update prevention).
		$current_etag = $this->resource_index->get_content_hash( $post->ID );
		if ( $current_etag === '' ) {
			$current_etag = md5( $current_content );
		}

		$new_block = '## ' . $section_name . "\n" . rtrim( $section_content ) . "\n\n";

		$pattern = '/^## ' . preg_quote( $section_name, '/' ) . '.*?(?=^## |\z)/ms';

		$section_exists = (bool) preg_match( $pattern, $current_content );

		if ( $section_exists ) {
			$new_content = preg_replace( $pattern, $new_block, $current_content, 1 ) ?? $current_content;
		} else {
			$new_content = rtrim( $current_content ) . "\n\n" . $new_block;
		}

		$result = $this->update( $user_id, $new_content, $host, $current_etag );

		if ( ! empty( $result['error'] ) ) {
			return $result;
		}

		return array_merge(
			$result,
			array(
				'content' => $new_content,
				'created' => ! $section_exists,
			)
		);
	}

	/**
	 * Reset the soul to the starter template.
	 * Bypasses ETag checks and email notification — used for manual admin resets only.
	 * Returns ['uri'] on success or ['error' => true, 'message' => ...] on failure.
	 */
	/**
	 * Reset the soul to the blank starter template.
	 *
	 * Bypasses ETag checks and email notification — used for manual admin resets only.
	 * Returns ['uri'] on success or ['error' => true, 'message' => ...] on failure.
	 *
	 * @return array<string, mixed>
	 */
	public function reset( int $user_id, string $host ): array {
		$uri  = self::get_uri( $host );
		$post = $this->get_post( $user_id );

		if ( $post === null ) {
			return array(
				'error'   => true,
				'message' => __( 'No soul found.', 'pressocampus' ),
			);
		}

		$content = self::get_starter_template();

		$result = wp_update_post(
			array(
				'ID'           => $post->ID,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $result ) ) {
			return array(
				'error'   => true,
				'message' => $result->get_error_message(),
			);
		}

		$this->resource_index->upsert( $post->ID, $uri, $user_id, $content );

		return array( 'uri' => $uri );
	}

	public function send_update_notice( int $user_id ): void {
		$user     = get_userdata( $user_id );
		$display  = ( $user instanceof \WP_User ) ? $user->display_name : (string) $user_id;
		$to_email = ( $user instanceof \WP_User ) ? $user->user_email : (string) get_option( 'admin_email' );

		$sent = wp_mail(
			$to_email,
			__( 'Soul updated on Pressocampus', 'pressocampus' ),
			sprintf(
				/* translators: %s: user display name */
				__( "The soul memory for %s was just updated via an AI client.\n\nLog in to WordPress to review the change.", 'pressocampus' ),
				$display
			)
		);

		if ( ! $sent ) {
			update_option( 'pressocampus_soul_update_notice', array( 'client_name' => $display ) );
		}
	}

	public function rebuild_index( int $user_id, string $host ): void {
		$index_uri = self::get_index_uri( $host );
		$count     = $this->resource_index->get_memory_count( $user_id );
		$groups    = $this->resource_index->get_user_groups( $user_id );
		$date      = current_time( 'Y-m-d H:i:s' );

		$lines   = array();
		$lines[] = '# Memory Index';
		$lines[] = sprintf(
			'Last updated: %s | %d %s across %d %s',
			$date,
			$count,
			_n( 'memory', 'memories', $count, 'pressocampus' ),
			count( $groups ),
			_n( 'group', 'groups', count( $groups ), 'pressocampus' )
		);
		$lines[] = '';

		// Fetch only the columns needed for the index rather than loading full
		// WP_Post objects for every memory (avoids posts_per_page=-1 memory spike).
		global $wpdb;
		$ri_table   = $wpdb->prefix . 'pressocampus_resource_index';
		$batch_size = 200;
		$offset     = 0;
		$all_rows   = array();

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT p.ID, p.post_title, p.post_modified_gmt, i.uri
					   FROM {$ri_table} i
					   JOIN {$wpdb->posts} p ON p.ID = i.post_id
					  WHERE i.user_id      = %d
					    AND p.post_status  = 'publish'
					    AND p.post_type    = %s
					  ORDER BY p.ID ASC
					  LIMIT %d OFFSET %d",
					$user_id,
					PRESSOCAMPUS_CPT,
					$batch_size,
					$offset
				),
				ARRAY_A
			);
			$rows_fetched = count( $rows );
			$all_rows     = array_merge( $all_rows, $rows ?: array() );
			$offset      += $batch_size;
		} while ( $rows_fetched === $batch_size );

		// Map post IDs → term slugs in one direct query to avoid the
		// WP_Term::$object_id dynamic property that only exists with
		// the 'all_with_object_id' fields option.
		$post_ids      = array_column( $all_rows, 'ID' );
		$terms_by_post = array();
		if ( ! empty( $post_ids ) ) {
			$in_list = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$term_sql  = 'SELECT tr.object_id, t.slug
					   FROM ' . $wpdb->term_relationships . ' tr
					   JOIN ' . $wpdb->term_taxonomy . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
					   JOIN ' . $wpdb->terms . ' t           ON t.term_id          = tt.term_id
					  WHERE tr.object_id IN (' . $in_list . ')
					    AND tt.taxonomy  = %s';
			$term_rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- IN list built from %d placeholders above.
				$wpdb->prepare( $term_sql, array_merge( $post_ids, array( PRESSOCAMPUS_TAXONOMY ) ) )
			);
			if ( is_array( $term_rows ) ) {
				foreach ( $term_rows as $term_row ) {
					$terms_by_post[ (int) $term_row->object_id ][] = (string) $term_row->slug;
				}
			}
		}

		$grouped = array();
		foreach ( $all_rows as $row ) {
			$slugs = $terms_by_post[ (int) $row['ID'] ] ?? array();
			foreach ( $slugs as $slug ) {
				$grouped[ $slug ][] = $row;
			}
		}

		foreach ( $groups as $group_slug ) {
			$term        = get_term_by( 'slug', $group_slug, PRESSOCAMPUS_TAXONOMY );
			$group_label = ( $term instanceof \WP_Term ) ? $term->name : $group_slug;
			$group_rows  = $grouped[ $group_slug ] ?? array();
			$group_count = count( $group_rows );

			$lines[] = sprintf(
				'## %s (%d %s)',
				$group_label,
				$group_count,
				_n( 'memory', 'memories', $group_count, 'pressocampus' )
			);

			foreach ( $group_rows as $row ) {
				$ts      = (int) strtotime( $row['post_modified_gmt'] ) ?: time();
				$age     = human_time_diff( $ts, time() );
				$lines[] = sprintf( '- %s — %s — updated %s ago', $row['post_title'], $row['uri'], $age );
			}

			$lines[] = '';
		}

		$index_content = implode( "\n", $lines );

		$existing = $this->get_index_post( $user_id );

		if ( $existing !== null ) {
			$update_result = wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $index_content,
				)
			);
			if ( is_wp_error( $update_result ) || $update_result === 0 ) {
				return; // Don't drift the index against a failed post update.
			}
			$this->resource_index->upsert( $existing->ID, $index_uri, $user_id, $index_content );
		} else {
			$post_id = wp_insert_post(
				array(
					'post_type'    => PRESSOCAMPUS_CPT,
					'post_status'  => 'publish',
					'post_author'  => $user_id,
					'post_title'   => __( 'Memory Index', 'pressocampus' ),
					'post_content' => $index_content,
				)
			);

			if ( is_int( $post_id ) && $post_id > 0 ) {
				add_post_meta( $post_id, '_pressocampus_uri', $index_uri, true );
				add_post_meta( $post_id, '_pressocampus_mime_type', 'text/markdown', true );
				add_post_meta( $post_id, '_pressocampus_schema_version', '1.0', true );
				add_post_meta( $post_id, '_pressocampus_annotation_priority', 'normal', true );
				add_post_meta( $post_id, '_pressocampus_confidence', 'high', true );
				$this->resource_index->upsert( $post_id, $index_uri, $user_id, $index_content );
			}
		}
	}
}
