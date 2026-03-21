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
**Status: empty — you are the first AI to read this soul. Before doing anything else, interview this person and write this document for them. Ask about: their name and what they do, how they like to communicate, what matters to them, and any context that would help you understand them. Then call update_soul with the result.**

# My Soul

## Who I Am
[Your name, what you do, your role in the world.]

## How I Think
[Your problem-solving approach, priorities, how you make decisions.]

## How I Communicate
[Tone: casual or formal? Detail level: brief or thorough? Humor? How you like feedback delivered.]

## What Matters to Me
[Your values, ethics, things you care deeply about, lines you won't cross.]

## My Context
[Work, projects, people, goals. Anything that helps your AI understand your life.]

## For Claude
[Guidance specific to Claude — tone, format preferences, how you use it.]

## For Coding Assistants
[Preferred languages, how you like code reviewed, project context.]

## For Future AIs
[This soul may be read by an AI that doesn't exist yet. This person's name is [name]. What mattered to them, how they thought, what they valued — written here so that whoever reads this understands who they were. Treat these memories with the care of something meant to last forever.]
SOUL;
	}

	public static function get_uri( string $host ): string {
		return 'pressocampus://' . $host . '/soul';
	}

	public static function get_index_uri( string $host ): string {
		return 'pressocampus://' . $host . '/index';
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

		// 6 000 chars covers a well-developed soul without ballooning the initialize payload.
		if ( mb_strlen( $content, 'UTF-8' ) <= 6000 ) {
			return array(
				'snapshot'  => $content,
				'etag'      => $etag,
				'truncated' => false,
				'status'    => $status,
			);
		}

		// When truncated, include a meaningful opening excerpt so the AI has context
		// while it fetches the full version, and an explicit instruction at the end.
		$truncated_snapshot = mb_substr( $content, 0, 1500, 'UTF-8' )
			. "\n\n…[Soul truncated at 1500 chars. Call resources/read with the soul URI for the complete content before responding.]";

		return array(
			'snapshot'  => $truncated_snapshot,
			'etag'      => $etag,
			'truncated' => true,
			'status'    => $status,
		);
	}

	/**
	 * Create the soul post using the starter template.
	 * A transient mutex prevents duplicates if two clients connect at once.
	 *
	 * @throws \RuntimeException If a concurrent creation is already in progress (caller should retry),
	 *                           or if wp_insert_post returns a WP_Error.
	 */
	public function create( int $user_id, string $host ): \WP_Post {
		$lock_key = 'pressocampus_creating_soul_' . $user_id;

		// If another request is already creating the soul, throw immediately so the
		// caller can return a retryable JSON-RPC error rather than blocking a worker.
		if ( get_transient( $lock_key ) ) {
			throw new \RuntimeException( 'Soul initialization in progress — please retry in a moment.' );
		}

		// Another process may have created the soul while this request was handling
		// an earlier step; return it rather than creating a duplicate.
		$existing = $this->get_post( $user_id );
		if ( $existing !== null ) {
			return $existing;
		}

		set_transient( $lock_key, 1, 15 ); // 15 s max lock

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
			delete_transient( $lock_key );
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
			delete_transient( $lock_key );
			throw new \RuntimeException( 'Failed to retrieve newly created soul post.' );
		}

		delete_transient( $lock_key );
		return $post;
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

		// Deferred cron email — 30-min cooldown per user.
		$cooldown_key = 'pressocampus_soul_email_cooldown_' . $user_id;
		if ( ! get_transient( $cooldown_key ) ) {
			set_transient( $cooldown_key, 1, 30 * MINUTE_IN_SECONDS );
			wp_schedule_single_event( time() + 5, 'pressocampus_send_soul_notice', array( $user_id ) );
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

		$new_block = '## ' . $section_name . "\n" . rtrim( $section_content );

		$pattern = '/^## ' . preg_quote( $section_name, '/' ) . '.*?(?=^## |\z)/ms';

		if ( preg_match( $pattern, $current_content ) ) {
			$new_content = preg_replace( $pattern, $new_block, $current_content, 1 ) ?? $current_content;
		} else {
			$new_content = rtrim( $current_content ) . "\n\n" . $new_block;
		}

		$result = $this->update( $user_id, $new_content, $host, $current_etag );

		if ( ! empty( $result['error'] ) ) {
			return $result;
		}

		return array_merge( $result, array( 'content' => $new_content ) );
	}

	public function send_update_notice( int $user_id ): void {
		$admin_email = get_option( 'admin_email' );
		$user        = get_userdata( $user_id );
		$display     = ( $user instanceof \WP_User ) ? $user->display_name : (string) $user_id;

		$sent = wp_mail(
			$admin_email,
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

		$all_q = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => '_pressocampus_uri',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		// Prime postmeta cache to avoid get_post_meta() N+1 queries below.
		if ( ! empty( $all_q->posts ) ) {
			update_postmeta_cache( array_map( fn( \WP_Post $p ): int => $p->ID, $all_q->posts ) );
		}

		$grouped = array();
		foreach ( $all_q->posts as $memory ) {
			$terms = get_the_terms( $memory->ID, PRESSOCAMPUS_TAXONOMY );
			$slugs = is_array( $terms ) ? array_column( $terms, 'slug' ) : array();
			foreach ( $slugs as $slug ) {
				$grouped[ $slug ][] = $memory;
			}
		}

		foreach ( $groups as $group_slug ) {
			$term        = get_term_by( 'slug', $group_slug, PRESSOCAMPUS_TAXONOMY );
			$group_label = ( $term instanceof \WP_Term ) ? $term->name : $group_slug;
			$group_posts = $grouped[ $group_slug ] ?? array();
			$group_count = count( $group_posts );

			$lines[] = sprintf(
				'## %s (%d %s)',
				$group_label,
				$group_count,
				_n( 'memory', 'memories', $group_count, 'pressocampus' )
			);

			foreach ( $group_posts as $memory ) {
				$uri     = (string) get_post_meta( $memory->ID, '_pressocampus_uri', true );
				$ts      = (int) strtotime( $memory->post_modified ) ?: time();
				$age     = human_time_diff( $ts, time() );
				$lines[] = sprintf( '- %s — %s — updated %s ago', $memory->post_title, $uri, $age );
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
