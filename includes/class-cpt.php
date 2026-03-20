<?php
/**
 * Custom Post Type and Taxonomy registration + lifecycle hooks.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CPT {

	public function __construct(
		private ResourceIndex $resource_index,
		private Soul $soul
	) {
		add_action( 'init', array( $this, 'register_cpt' ) );
		add_action( 'init', array( $this, 'register_taxonomy' ) );
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'save_post_' . PRESSOCAMPUS_CPT, array( $this, 'on_save_post' ), 10, 3 );
		add_filter( 'wp_revisions_to_keep', array( $this, 'cap_revisions' ), 10, 2 );
	}

	public function register_cpt(): void {
		register_post_type(
			PRESSOCAMPUS_CPT,
			array(
				'label'              => __( 'Pressocampus Memory', 'pressocampus' ),
				'labels'             => array(
					'name'          => __( 'Memories', 'pressocampus' ),
					'singular_name' => __( 'Memory', 'pressocampus' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => false,
				'show_in_menu'       => false,
				'show_in_rest'       => false,
				'query_var'          => false,
				'rewrite'            => false,
				'has_archive'        => false,
				'hierarchical'       => false,
				'supports'           => array( 'title', 'editor', 'revisions', 'custom-fields' ),
				'taxonomies'         => array( PRESSOCAMPUS_TAXONOMY ),
				'capability_type'    => 'post',
				'map_meta_cap'       => true,
			)
		);
	}

	public function register_taxonomy(): void {
		register_taxonomy(
			PRESSOCAMPUS_TAXONOMY,
			PRESSOCAMPUS_CPT,
			array(
				'label'             => __( 'Memory Group', 'pressocampus' ),
				'labels'            => array(
					'name'          => __( 'Memory Groups', 'pressocampus' ),
					'singular_name' => __( 'Memory Group', 'pressocampus' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => false,
				'show_in_rest'      => false,
				'rewrite'           => false,
				'show_admin_column' => false,
			)
		);
	}

	public function register_meta(): void {
		$common = array(
			'object_subtype' => PRESSOCAMPUS_CPT,
			'single'         => true,
			'show_in_rest'   => false,
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_uri',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'UUID4-based resource URI', 'pressocampus' ),
					'sanitize_callback' => 'sanitize_text_field',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_mime_type',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'MIME type of the resource', 'pressocampus' ),
					'default'           => 'text/markdown',
					'sanitize_callback' => 'sanitize_mime_type',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_description',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Human-readable description', 'pressocampus' ),
					'sanitize_callback' => 'sanitize_textarea_field',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_size',
			array_merge(
				$common,
				array(
					'type'              => 'integer',
					'description'       => __( 'Content size in UTF-8 characters', 'pressocampus' ),
					'sanitize_callback' => 'absint',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_annotation_priority',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Annotation priority: critical/important/normal/low', 'pressocampus' ),
					'default'           => 'normal',
					'sanitize_callback' => static function ( string $v ): string {
						return in_array( $v, array( 'critical', 'important', 'normal', 'low' ), true ) ? $v : 'normal';
					},
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_expires_at',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Expiry datetime (ISO 8601) or empty', 'pressocampus' ),
					'sanitize_callback' => 'sanitize_text_field',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_related',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Comma-separated related URIs', 'pressocampus' ),
					'sanitize_callback' => 'sanitize_text_field',
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_confidence',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Confidence level: high/medium/low', 'pressocampus' ),
					'default'           => 'medium',
					'sanitize_callback' => static function ( string $v ): string {
						return in_array( $v, array( 'high', 'medium', 'low' ), true ) ? $v : 'medium';
					},
				)
			)
		);

		register_post_meta(
			PRESSOCAMPUS_CPT,
			'_pressocampus_schema_version',
			array_merge(
				$common,
				array(
					'type'              => 'string',
					'description'       => __( 'Schema version string', 'pressocampus' ),
					'default'           => '1.0',
					'sanitize_callback' => 'sanitize_text_field',
				)
			)
		);
	}

	public function on_save_post( int $post_id, \WP_Post $post, bool $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP save_post callback; $update is required by hook signature
		// Skip auto-saves and revisions.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		// Compute and persist byte-accurate size.
		$raw_content = self::get_raw_content( $post_id );
		update_post_meta( $post_id, '_pressocampus_size', mb_strlen( $raw_content, 'UTF-8' ) );

		// Keep the index in sync.
		$uri = (string) get_post_meta( $post_id, '_pressocampus_uri', true );
		if ( $uri !== '' ) {
			$this->resource_index->upsert( $post_id, $uri, (int) $post->post_author, $raw_content );
		}

		do_action( 'pressocampus_memory_changed', $post_id, (int) $post->post_author );
	}

	public function cap_revisions( int $num, \WP_Post $post ): int {
		if ( $post->post_type !== PRESSOCAMPUS_CPT ) {
			return $num;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost';
		$uri  = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );

		if ( $uri === Soul::get_uri( $host ) ) {
			return 20;
		}

		return 5;
	}

	public function expire_old_memories(): void {
		// Filter in SQL — only loads posts whose expiry has already passed.
		// Batches of 200 to avoid memory spikes; loops until the batch is empty
		// so a single cron run clears all overdue memories across all users.
		do {
			$q = new \WP_Query(
				array(
					'post_type'      => PRESSOCAMPUS_CPT,
					'post_status'    => 'publish',
					'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- intentional: expire job scans all overdue memories in batches
					'no_found_rows'  => true,
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'meta_query'     => array(
						array(
							'key'     => '_pressocampus_expires_at',
							'value'   => current_time( 'mysql' ),
							'compare' => '<=',
							'type'    => 'DATETIME',
						),
					),
				)
			);

			foreach ( $q->posts as $post ) {
				wp_update_post(
					array(
						'ID'          => $post->ID,
						'post_status' => 'pressocampus_expired',
					)
				);
			}
		} while ( $q->post_count === 200 );
	}

	/**
	 * Generate a new memory URI: pressocampus://{host}/memory/{uuid4}
	 */
	public static function generate_uri( string $host ): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			$uuid = wp_generate_uuid4();
		} else {
			// Fallback for environments where the function is not yet loaded.
			$uuid = sprintf(
				'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
				random_int( 0, 0xffff ),
				random_int( 0, 0xffff ),
				random_int( 0, 0xffff ),
				random_int( 0, 0x0fff ) | 0x4000,
				random_int( 0, 0x3fff ) | 0x8000,
				random_int( 0, 0xffff ),
				random_int( 0, 0xffff ),
				random_int( 0, 0xffff )
			);
		}

		return 'pressocampus://' . $host . '/memory/' . $uuid;
	}

	/**
	 * Map a priority label to a 0–1 float for sorting.
	 */
	public static function priority_to_float( string $priority ): float {
		return match ( $priority ) {
			'critical'  => 1.0,
			'important' => 0.75,
			'normal'    => 0.5,
			'low'       => 0.25,
			default     => 0.5,
		};
	}

	/**
	 * Retrieve a post by its URI, verifying ownership.
	 */
	public function get_post_by_uri( string $uri, int $user_id ): ?\WP_Post {
		$row = $this->resource_index->get_by_uri( $uri );

		if ( $row === null ) {
			return null;
		}

		if ( (int) $row['user_id'] !== $user_id ) {
			return null;
		}

		$post = get_post( (int) $row['post_id'] );
		return ( $post instanceof \WP_Post ) ? $post : null;
	}

	/**
	 * Return raw post content, bypassing all content filters.
	 */
	public static function get_raw_content( int $post_id ): string {
		return (string) get_post_field( 'post_content', $post_id );
	}
}
