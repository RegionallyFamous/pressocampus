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

	// -----------------------------------------------------------------------
	// Starter template
	// -----------------------------------------------------------------------

	/**
	 * The canonical starter template written into every new soul.
	 *
	 * The "Status: empty" line at the top is removed by update() once the user
	 * has filled in their soul for the first time.
	 */
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

	// -----------------------------------------------------------------------
	// URI helpers
	// -----------------------------------------------------------------------

	public static function get_uri( string $host ): string {
		return 'pressocampus://' . $host . '/soul';
	}

	public static function get_index_uri( string $host ): string {
		return 'pressocampus://' . $host . '/index';
	}

	/**
	 * Returns true for the two permanently protected URIs (soul + index).
	 */
	public static function is_protected( string $uri, string $host ): bool {
		return $uri === self::get_uri( $host ) || $uri === self::get_index_uri( $host );
	}

	// -----------------------------------------------------------------------
	// Read operations
	// -----------------------------------------------------------------------

	/**
	 * Retrieve the soul WP_Post for a user, or null if not yet created.
	 */
	public function get_post( int $user_id ): ?\WP_Post {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';

		$q = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_pressocampus_uri',
						'value' => self::get_uri( $host ),
					),
				),
			)
		);

		$post = $q->posts[0] ?? null;
		return ( $post instanceof \WP_Post ) ? $post : null;
	}

	/**
	 * Return the index WP_Post for a user, or null.
	 */
	public function get_index_post( int $user_id ): ?\WP_Post {
		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';

		$q = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => array( 'publish', 'draft' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_pressocampus_uri',
						'value' => self::get_index_uri( $host ),
					),
				),
			)
		);

		$post = $q->posts[0] ?? null;
		return ( $post instanceof \WP_Post ) ? $post : null;
	}

	// -----------------------------------------------------------------------
	// Status helpers
	// -----------------------------------------------------------------------

	/**
	 * Returns 'empty' if the soul has never been written, 'complete' otherwise.
	 */
	public function get_status( int $user_id ): string {
		$post = $this->get_post( $user_id );
		if ( $post === null ) {
			return 'empty';
		}

		$content = CPT::get_raw_content( $post->ID );
		return str_contains( $content, 'Status: empty' ) ? 'empty' : 'complete';
	}

	/**
	 * Return a snapshot array suitable for inclusion in an MCP initialize response.
	 *
	 * Returns:
	 *   snapshot   string  Soul content (possibly truncated)
	 *   etag       string  md5 hash of full content
	 *   truncated  bool    Whether the snapshot was cut short
	 *   status     string  'empty' or 'complete'
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

		if ( mb_strlen( $content, 'UTF-8' ) <= 2048 ) {
			return array(
				'snapshot'  => $content,
				'etag'      => $etag,
				'truncated' => false,
				'status'    => $status,
			);
		}

		$truncated_snapshot = mb_substr( $content, 0, 500, 'UTF-8' )
			. "\n\n[Soul truncated — use resources/read to get full content]";

		return array(
			'snapshot'  => $truncated_snapshot,
			'etag'      => $etag,
			'truncated' => true,
			'status'    => $status,
		);
	}

	// -----------------------------------------------------------------------
	// Write operations
	// -----------------------------------------------------------------------

	/**
	 * Create the soul post for a user using the starter template.
	 *
	 * @param int    $user_id WordPress user ID to own the soul.
	 * @param string $host    Site hostname for URI namespacing.
	 * @return \WP_Post The newly created soul post.
	 * @throws \RuntimeException If wp_insert_post fails or the post cannot be retrieved.
	 */
	public function create( int $user_id, string $host ): \WP_Post {
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
			// Surface the error as a runtime exception — callers handle it.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception is caught by caller, not output to HTML
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
	}

	/**
	 * Fully replace the soul content.
	 *
	 * ETag-protected: if $etag is provided and does not match the stored hash,
	 * returns a 409-style error array rather than overwriting.
	 *
	 * On success returns: ['uri' => string, 'etag' => string]
	 * On failure returns: ['error' => true, 'code' => string, 'message' => string]
	 */
	public function update( int $user_id, string $content, string $host, ?string $etag = null ): array {
		$uri  = self::get_uri( $host );
		$post = $this->get_post( $user_id );

		if ( $post === null ) {
			$post = $this->create( $user_id, $host );
		}

		// ETag conflict check.
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

		// Notify admin about the soul update.
		$admin_email = get_option( 'admin_email' );
		$user        = get_userdata( $user_id );
		$client_name = $user instanceof \WP_User ? $user->display_name : (string) $user_id;

		$mail_sent = wp_mail(
			$admin_email,
			__( 'Soul updated on Pressocampus', 'pressocampus' ),
			sprintf(
				/* translators: %s: user display name */
				__( "The soul memory for %s was just updated via an AI client.\n\nLog in to WordPress to review the change.", 'pressocampus' ),
				$client_name
			)
		);

		if ( ! $mail_sent ) {
			update_option( 'pressocampus_soul_update_notice', $client_name );
		}

		$new_hash = md5( $content );
		return array(
			'uri'  => $uri,
			'etag' => $new_hash,
		);
	}

	/**
	 * Replace a single `## Section` within the soul without touching the rest.
	 *
	 * If the section is not found it is appended at the end.
	 *
	 * Returns: ['uri' => string, 'etag' => string, 'content' => string]
	 *       or ['error' => true, 'code' => string, 'message' => string]
	 */
	public function update_section( int $user_id, string $section_name, string $section_content, string $host ): array {
		$post = $this->get_post( $user_id );
		if ( $post === null ) {
			$post = $this->create( $user_id, $host );
		}

		$current_content = CPT::get_raw_content( $post->ID );

		// Build the replacement block.
		$new_block = '## ' . $section_name . "\n" . rtrim( $section_content );

		// Match '## {section_name}' up to (but not including) the next '##' or end-of-string.
		$pattern = '/^## ' . preg_quote( $section_name, '/' ) . '.*?(?=^## |\z)/ms';

		if ( preg_match( $pattern, $current_content ) ) {
			$new_content = preg_replace( $pattern, $new_block, $current_content, 1 ) ?? $current_content;
		} else {
			// Section not found — append it.
			$new_content = rtrim( $current_content ) . "\n\n" . $new_block;
		}

		$result = $this->update( $user_id, $new_content, $host );

		if ( ! empty( $result['error'] ) ) {
			return $result;
		}

		return array_merge( $result, array( 'content' => $new_content ) );
	}

	// -----------------------------------------------------------------------
	// Index rebuild
	// -----------------------------------------------------------------------

	/**
	 * Rebuild the memory index post for $user_id.
	 *
	 * The index is a Markdown document that lists every published memory
	 * grouped by pressocampus_group taxonomy term.
	 */
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

		foreach ( $groups as $group_slug ) {
			$term        = get_term_by( 'slug', $group_slug, PRESSOCAMPUS_TAXONOMY );
			$group_label = ( $term instanceof \WP_Term ) ? $term->name : $group_slug;

			$q = new \WP_Query(
				array(
					'post_type'      => PRESSOCAMPUS_CPT,
					'post_status'    => 'publish',
					'author'         => $user_id,
					'posts_per_page' => -1,
					'no_found_rows'  => true,
					'tax_query'      => array(
						array(
							'taxonomy' => PRESSOCAMPUS_TAXONOMY,
							'field'    => 'slug',
							'terms'    => $group_slug,
						),
					),
					'meta_query'     => array(
						array(
							'key'     => '_pressocampus_uri',
							'compare' => 'EXISTS',
						),
					),
				)
			);

			$group_count = count( $q->posts );
			$lines[]     = sprintf(
				'## %s (%d %s)',
				$group_label,
				$group_count,
				_n( 'memory', 'memories', $group_count, 'pressocampus' )
			);

			foreach ( $q->posts as $memory ) {
				$uri     = (string) get_post_meta( $memory->ID, '_pressocampus_uri', true );
				$age     = human_time_diff( strtotime( $memory->post_modified ), time() );
				$lines[] = sprintf( '- %s — %s — updated %s ago', $memory->post_title, $uri, $age );
			}

			$lines[] = '';
		}

		$index_content = implode( "\n", $lines );

		// Update existing index post or create a new one.
		$existing = $this->get_index_post( $user_id );

		if ( $existing !== null ) {
			wp_update_post(
				array(
					'ID'           => $existing->ID,
					'post_content' => $index_content,
				)
			);
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
