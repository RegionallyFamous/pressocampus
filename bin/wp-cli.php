<?php
/**
 * WP-CLI integration for Pressocampus.
 *
 * Registration: add the following to wp-cli.yml
 *   require:
 *     - bin/wp-cli.php
 *
 * Or in wp-config.php (before the "/* That's all" line):
 *   if ( defined( 'WP_CLI' ) && WP_CLI ) {
 *       require_once __DIR__ . '/bin/wp-cli.php';
 *   }
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

if ( ! defined( 'WP_CLI' ) ) {
	return;
}

use Pressocampus\CPT;
use Pressocampus\Soul;
use Pressocampus\ResourceIndex;
use Pressocampus\AuditLog;

/**
 * Manage Pressocampus memories from the command line.
 *
 * @when after_wp_load
 */
class Pressocampus_CLI {

	// =========================================================================
	// list
	// =========================================================================

	/**
	 * List memories for a user.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : WordPress user ID or login. Default: current user.
	 *
	 * [--group=<group>]
	 * : Filter by group slug.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--url=<url>]
	 * : Multisite: target this site URL before running.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus list
	 *   wp pressocampus list --group=work --format=json
	 *   wp pressocampus list --user=nick --format=csv
	 *
	 * @subcommand list
	 */
	public function list( array $args, array $assoc_args ): void {
		$user_id = $this->resolve_user( $assoc_args );
		$group   = $assoc_args['group'] ?? '';
		$format  = $assoc_args['format'] ?? 'table';

		$query_args = array(
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
		);

		if ( $group !== '' ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => PRESSOCAMPUS_TAXONOMY,
					'field'    => 'slug',
					'terms'    => sanitize_title( $group ),
				),
			);
		}

		$q = new WP_Query( $query_args );

		if ( empty( $q->posts ) ) {
			WP_CLI::line( 'No memories found.' );
			return;
		}

		$items = array();
		foreach ( $q->posts as $post ) {
			$items[] = array(
				'uri'        => (string) get_post_meta( $post->ID, '_pressocampus_uri', true ),
				'name'       => $post->post_title,
				'group'      => $this->get_post_group( $post->ID ),
				'priority'   => (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' ),
				'confidence' => (string) ( get_post_meta( $post->ID, '_pressocampus_confidence', true ) ?: 'medium' ),
				'updated'    => $post->post_modified,
			);
		}

		WP_CLI\Utils\format_items( $format, $items, array( 'uri', 'name', 'group', 'priority', 'confidence', 'updated' ) );
	}

	// =========================================================================
	// get <uri>
	// =========================================================================

	/**
	 * Show the full content of a single memory.
	 *
	 * ## ARGUMENTS
	 *
	 * <uri>
	 * : The pressocampus:// URI of the memory.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus get pressocampus://mysite.com/memory/abc123
	 *   wp pressocampus get pressocampus://mysite.com/soul
	 *
	 * @subcommand get
	 */
	public function get( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( '<uri> is required.' );
		}

		$uri  = (string) $args[0];
		$post = $this->find_post_by_uri( $uri );

		if ( $post === null ) {
			WP_CLI::error( "Memory not found: {$uri}" );
		}

		WP_CLI::line( '# ' . $post->post_title );
		WP_CLI::line( '' );
		WP_CLI::line( CPT::get_raw_content( $post->ID ) );
	}

	// =========================================================================
	// delete <uri>
	// =========================================================================

	/**
	 * Hard-delete a memory by URI.
	 *
	 * ## ARGUMENTS
	 *
	 * <uri>
	 * : The pressocampus:// URI of the memory to delete.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus delete pressocampus://mysite.com/memory/abc123
	 *   wp pressocampus delete pressocampus://mysite.com/memory/abc123 --yes
	 *
	 * @subcommand delete
	 */
	public function delete( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( '<uri> is required.' );
		}

		$uri  = (string) $args[0];
		$host = (string) ( parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		if ( Soul::is_protected( $uri, $host ) ) {
			WP_CLI::error( 'Cannot delete a protected memory (soul or index). Use `wp pressocampus export` to back it up first.' );
		}

		$post = $this->find_post_by_uri( $uri );
		if ( $post === null ) {
			WP_CLI::error( "Memory not found: {$uri}" );
		}

		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Permanently delete \"{$post->post_title}\" ({$uri})?" );
		}

		// Strip deleted URI from _pressocampus_related on other posts.
		$this->strip_related_uri( $uri );

		// Remove from resource index.
		$index = new ResourceIndex();
		$index->delete_by_post_id( $post->ID );

		// Hard-delete the post.
		$deleted = wp_delete_post( $post->ID, true );
		if ( ! $deleted ) {
			WP_CLI::error( "Failed to delete post ID {$post->ID}." );
		}

		WP_CLI::success( "Deleted: {$uri}" );
	}

	// =========================================================================
	// export
	// =========================================================================

	/**
	 * Export memories to a JSON file or a folder of Markdown files.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : WordPress user ID or login. Default: current user.
	 *
	 * [--format=<format>]
	 * : Export format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - markdown-folder
	 * ---
	 *
	 * [--output=<path>]
	 * : Output file (json) or directory (markdown-folder).
	 *   Default: ./pressocampus-export-{date}.json or ./pressocampus-export-{date}/
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus export
	 *   wp pressocampus export --format=json --output=brain.json
	 *   wp pressocampus export --format=markdown-folder --output=./brain/
	 *   wp pressocampus export --user=nick --format=markdown-folder
	 *
	 * @subcommand export
	 */
	public function export( array $args, array $assoc_args ): void {
		$user_id = $this->resolve_user( $assoc_args );
		$format  = $assoc_args['format'] ?? 'json';
		$date    = gmdate( 'Y-m-d' );
		$host    = (string) ( parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );

		// Collect all published memories for this user.
		$q = new WP_Query(
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

		$soul_uri     = Soul::get_uri( $host );
		$soul_post    = null;
		$memory_posts = array();

		foreach ( $q->posts as $post ) {
			$uri = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
			if ( $uri === $soul_uri ) {
				$soul_post = $post;
			} else {
				$memory_posts[] = $post;
			}
		}

		if ( $format === 'json' ) {
			$output = (string) ( $assoc_args['output'] ?? "./pressocampus-export-{$date}.json" );

			$memories = array();
			if ( $soul_post !== null ) {
				$memories[] = $this->post_to_export_array( $soul_post, true );
			}
			foreach ( $memory_posts as $post ) {
				$memories[] = $this->post_to_export_array( $post, false );
			}

			$json = wp_json_encode( $memories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( $json === false ) {
				WP_CLI::error( 'Failed to JSON-encode memories: ' . json_last_error_msg() );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			if ( file_put_contents( $output, $json ) === false ) {
				WP_CLI::error( "Cannot write to: {$output}" );
			}

			$count = count( $memories );
			WP_CLI::success( "Exported {$count} " . _n( 'memory', 'memories', $count, 'pressocampus' ) . " to {$output}" );

		} elseif ( $format === 'markdown-folder' ) {
			$output = rtrim( (string) ( $assoc_args['output'] ?? "./pressocampus-export-{$date}" ), DIRECTORY_SEPARATOR . '/' );

			if ( ! is_dir( $output ) && ! wp_mkdir_p( $output ) ) {
				WP_CLI::error( "Cannot create directory: {$output}" );
			}

			$count = 0;
			if ( $soul_post !== null ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( "{$output}/SOUL.md", $this->post_to_markdown( $soul_post, true ) );
				++$count;
			}

			foreach ( $memory_posts as $post ) {
				$slug      = sanitize_file_name( $post->post_title ) ?: ( 'memory-' . $post->ID );
				$file_path = "{$output}/{$slug}.md";
				if ( file_exists( $file_path ) ) {
					$file_path = "{$output}/{$slug}-{$post->ID}.md";
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $file_path, $this->post_to_markdown( $post, false ) );
				++$count;
			}

			WP_CLI::success( "Exported {$count} " . _n( 'file', 'files', $count, 'pressocampus' ) . " to {$output}/" );

		} else {
			WP_CLI::error( "Unknown format '{$format}'. Use: json, markdown-folder." );
		}
	}

	// =========================================================================
	// import
	// =========================================================================

	/**
	 * Import memories from a JSON file or a directory of Markdown files.
	 *
	 * ## OPTIONS
	 *
	 * --file=<path>
	 * : JSON file or directory of .md files to import.
	 *
	 * [--user=<user>]
	 * : Target WordPress user ID or login. Default: current user.
	 *
	 * [--yes]
	 * : Skip confirmation when overwriting an existing soul.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus import --file=brain.json
	 *   wp pressocampus import --file=brain.json --user=nick --yes
	 *   wp pressocampus import --file=./brain/
	 *
	 * @subcommand import
	 */
	public function import( array $args, array $assoc_args ): void {
		$file    = $assoc_args['file'] ?? '';
		$user_id = $this->resolve_user( $assoc_args );
		$skip    = isset( $assoc_args['yes'] );

		if ( $file === '' ) {
			WP_CLI::error( '--file is required.' );
		}
		if ( ! file_exists( $file ) ) {
			WP_CLI::error( "Not found: {$file}" );
		}

		$host  = (string) ( parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$index = new ResourceIndex();
		$soul  = new Soul( $index );

		if ( is_dir( $file ) ) {
			$this->import_markdown_folder( $file, $user_id, $host, $index, $soul, $skip );
		} else {
			$this->import_json( $file, $user_id, $host, $index, $soul, $skip );
		}
	}

	// =========================================================================
	// migrate-domain
	// =========================================================================

	/**
	 * Rewrite all Pressocampus URIs from an old domain to a new domain.
	 *
	 * Updates: post meta (_pressocampus_uri, _pressocampus_related)
	 *          and the pressocampus_resource_index table.
	 *
	 * ## OPTIONS
	 *
	 * --from=<host>
	 * : The old domain (e.g. old.com).
	 *
	 * --to=<host>
	 * : The new domain (e.g. new.com).
	 *
	 * [--dry-run]
	 * : Preview changes without writing to the database.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus migrate-domain --from=old.com --to=new.com
	 *   wp pressocampus migrate-domain --from=old.com --to=new.com --dry-run
	 *
	 * @subcommand migrate-domain
	 */
	public function migrate_domain( array $args, array $assoc_args ): void {
		$from    = trim( $assoc_args['from'] ?? '' );
		$to      = trim( $assoc_args['to'] ?? '' );
		$dry_run = isset( $assoc_args['dry-run'] );

		if ( $from === '' || $to === '' ) {
			WP_CLI::error( '--from and --to are required.' );
		}
		if ( $from === $to ) {
			WP_CLI::error( '--from and --to are the same host. Nothing to do.' );
		}

		global $wpdb;

		$old_prefix = 'pressocampus://' . $from . '/';
		$new_prefix = 'pressocampus://' . $to . '/';

		// ---- 1. _pressocampus_uri meta ----------------------------------------
		$uri_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_id, meta_value
				   FROM {$wpdb->postmeta}
				  WHERE meta_key   = '_pressocampus_uri'
				    AND meta_value LIKE %s",
				$wpdb->esc_like( $old_prefix ) . '%'
			),
			ARRAY_A
		) ?: array();

		$changed_uris = 0;
		foreach ( $uri_rows as $row ) {
			$new_uri = str_replace( $old_prefix, $new_prefix, (string) $row['meta_value'] );
			if ( $dry_run ) {
				WP_CLI::line( "[dry-run] URI  post={$row['post_id']}: {$row['meta_value']} → {$new_uri}" );
			} else {
				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_uri ),
					array( 'meta_id' => $row['meta_id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
			++$changed_uris;
		}

		// ---- 2. pressocampus_resource_index table -----------------------------
		$index_table = $wpdb->prefix . 'pressocampus_resource_index';
		$index_rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, uri FROM {$index_table} WHERE uri LIKE %s",
				$wpdb->esc_like( $old_prefix ) . '%'
			),
			ARRAY_A
		) ?: array();

		$changed_index = 0;
		foreach ( $index_rows as $row ) {
			$new_uri = str_replace( $old_prefix, $new_prefix, (string) $row['uri'] );
			if ( $dry_run ) {
				WP_CLI::line( "[dry-run] Index row={$row['id']}: {$row['uri']} → {$new_uri}" );
			} else {
				$wpdb->update(
					$index_table,
					array( 'uri' => $new_uri ),
					array( 'id' => $row['id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
			++$changed_index;
		}

		// ---- 3. _pressocampus_related meta ------------------------------------
		$related_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_id, meta_value
				   FROM {$wpdb->postmeta}
				  WHERE meta_key   = '_pressocampus_related'
				    AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $old_prefix ) . '%'
			),
			ARRAY_A
		) ?: array();

		$changed_related = 0;
		foreach ( $related_rows as $row ) {
			$new_val = str_replace( $old_prefix, $new_prefix, (string) $row['meta_value'] );
			if ( $dry_run ) {
				WP_CLI::line( "[dry-run] Related post={$row['post_id']}: {$row['meta_value']} → {$new_val}" );
			} else {
				$wpdb->update(
					$wpdb->postmeta,
					array( 'meta_value' => $new_val ),
					array( 'meta_id' => $row['meta_id'] ),
					array( '%s' ),
					array( '%d' )
				);
			}
			++$changed_related;
		}

		$mode = $dry_run ? '[dry-run] Would update' : 'Updated';
		WP_CLI::success(
			"{$mode}: {$changed_uris} URI meta value(s), " .
			"{$changed_index} index row(s), " .
			"{$changed_related} related meta value(s)."
		);
	}

	// =========================================================================
	// flush-cache
	// =========================================================================

	/**
	 * Clear all Pressocampus transients and object-cache entries.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus flush-cache
	 *
	 * @subcommand flush-cache
	 */
	public function flush_cache( array $args, array $assoc_args ): void {
		global $wpdb;

		// Remove all Pressocampus transients from the options table.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$deleted = (int) $wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			"DELETE FROM {$wpdb->options}
			  WHERE option_name LIKE '\_transient\_pressocampus\_%'
			     OR option_name LIKE '\_transient\_timeout\_pressocampus\_%'"
		);

		// Flush the object-cache group (WP 6.1+) or fall back to full flush.
		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( 'pressocampus' );
		} else {
			wp_cache_flush();
		}

		WP_CLI::success( "Flushed {$deleted} transient(s) and cleared object cache." );
	}

	// =========================================================================
	// audit
	// =========================================================================

	/**
	 * Show the Pressocampus audit log.
	 *
	 * ## OPTIONS
	 *
	 * [--user=<user>]
	 * : Filter by WordPress user ID or login.
	 *
	 * [--days=<n>]
	 * : Show entries from the last N days.
	 * ---
	 * default: 7
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus audit
	 *   wp pressocampus audit --days=30
	 *   wp pressocampus audit --user=nick --format=csv > audit.csv
	 *
	 * @subcommand audit
	 */
	public function audit( array $args, array $assoc_args ): void {
		$user_id = $this->resolve_user( $assoc_args, false );
		$days    = max( 1, (int) ( $assoc_args['days'] ?? 7 ) );
		$format  = $assoc_args['format'] ?? 'table';

		global $wpdb;
		$table = $wpdb->prefix . 'pressocampus_audit_log';
		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		if ( $user_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT created_at AS date,
					        oauth_client_name AS agent,
					        action,
					        memory_name AS memory,
					        context
					   FROM {$table}
					  WHERE created_at >= %s
					    AND user_id     = %d
					  ORDER BY id DESC",
					$since,
					$user_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT created_at AS date,
					        oauth_client_name AS agent,
					        action,
					        memory_name AS memory,
					        context
					   FROM {$table}
					  WHERE created_at >= %s
					  ORDER BY id DESC",
					$since
				),
				ARRAY_A
			);
		}

		if ( empty( $rows ) ) {
			WP_CLI::line( "No audit entries in the last {$days} day(s)." );
			return;
		}

		WP_CLI\Utils\format_items( $format, $rows, array( 'date', 'agent', 'action', 'memory', 'context' ) );
	}

	// =========================================================================
	// stats
	// =========================================================================

	/**
	 * Show memory statistics for all users.
	 *
	 * ## EXAMPLES
	 *
	 *   wp pressocampus stats
	 *
	 * @subcommand stats
	 */
	public function stats( array $args, array $assoc_args ): void {
		global $wpdb;

		$index_table = $wpdb->prefix . 'pressocampus_resource_index';
		$audit_table = $wpdb->prefix . 'pressocampus_audit_log';
		$host        = (string) ( parse_url( home_url(), PHP_URL_HOST ) ?? 'localhost' );
		$soul_uri    = Soul::get_uri( $host );
		$index_uri   = Soul::get_index_uri( $host );

		// --- Memories per user (excluding soul + index) -----------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.user_id, COUNT(*) AS memory_count
				   FROM {$index_table} i
				   JOIN {$wpdb->posts} p ON p.ID = i.post_id
				  WHERE i.uri NOT IN (%s, %s)
				    AND p.post_status = 'publish'
				  GROUP BY i.user_id
				  ORDER BY memory_count DESC",
				$soul_uri,
				$index_uri
			),
			ARRAY_A
		) ?: array();

		WP_CLI::line( '== Memories per User ==' );

		if ( empty( $user_rows ) ) {
			WP_CLI::line( '  (none)' );
		} else {
			$soul_svc = new Soul( new ResourceIndex() );
			$table    = array();
			foreach ( $user_rows as $row ) {
				$uid     = (int) $row['user_id'];
				$wpuser  = get_userdata( $uid );
				$table[] = array(
					'user'         => $wpuser ? $wpuser->user_login : "(id:{$uid})",
					'display_name' => $wpuser ? $wpuser->display_name : '',
					'memories'     => (int) $row['memory_count'],
					'soul_status'  => $soul_svc->get_status( $uid ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $table, array( 'user', 'display_name', 'memories', 'soul_status' ) );
		}

		// --- DB table counts --------------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$index_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index_table}" );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$audit_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$audit_table}" );
		$post_count  = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				PRESSOCAMPUS_CPT
			)
		);

		WP_CLI::line( '' );
		WP_CLI::line( '== DB Table Row Counts ==' );
		WP_CLI::line( "  pressocampus_resource posts : {$post_count}" );
		WP_CLI::line( "  resource_index rows         : {$index_count}" );
		WP_CLI::line( "  audit_log rows              : {$audit_count}" );

		// --- Last activity ----------------------------------------------------
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$last = $wpdb->get_var( "SELECT created_at FROM {$audit_table} ORDER BY id DESC LIMIT 1" );
		WP_CLI::line( '' );
		WP_CLI::line( '== Last Activity ==' );
		WP_CLI::line( '  ' . ( $last ?: '(no audit entries yet)' ) );
	}

	// =========================================================================
	// Private helpers
	// =========================================================================

	/**
	 * Resolve --user=<user> to a WP user ID.
	 *
	 * @param array<string,mixed> $assoc_args CLI associative args (expects optional 'user' key).
	 * @param bool                $required   When true, WP_CLI::error() if no user resolves.
	 */
	private function resolve_user( array $assoc_args, bool $required = true ): int {
		if ( isset( $assoc_args['user'] ) ) {
			$arg  = $assoc_args['user'];
			$user = is_numeric( $arg )
				? get_user_by( 'id', (int) $arg )
				: get_user_by( 'login', (string) $arg );

			if ( ! $user instanceof WP_User ) {
				WP_CLI::error( "User not found: {$arg}" );
			}

			return $user->ID;
		}

		$user_id = get_current_user_id();
		if ( $required && $user_id === 0 ) {
			WP_CLI::error( 'No user resolved. Specify one with --user=<id_or_login>.' );
		}

		return $user_id;
	}

	/**
	 * Find any pressocampus_resource post by its URI meta value.
	 */
	private function find_post_by_uri( string $uri ): ?WP_Post {
		$q = new WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => array( 'publish', 'draft', 'pressocampus_expired' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'   => '_pressocampus_uri',
						'value' => $uri,
					),
				),
			)
		);

		$post = $q->posts[0] ?? null;
		return ( $post instanceof WP_Post ) ? $post : null;
	}

	/**
	 * Return the first taxonomy group slug for a post, or empty string.
	 */
	private function get_post_group( int $post_id ): string {
		$terms = get_the_terms( $post_id, PRESSOCAMPUS_TAXONOMY );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			return $terms[0]->slug;
		}
		return '';
	}

	/**
	 * Remove $uri from the _pressocampus_related meta of every post that contains it.
	 */
	private function strip_related_uri( string $uri ): void {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id
				   FROM {$wpdb->postmeta}
				  WHERE meta_key   = '_pressocampus_related'
				    AND meta_value LIKE %s",
				'%' . $wpdb->esc_like( $uri ) . '%'
			)
		) ?: array();

		foreach ( $ids as $pid ) {
			$meta  = (string) get_post_meta( (int) $pid, '_pressocampus_related', true );
			$parts = array_filter(
				array_map( 'trim', explode( ',', $meta ) ),
				fn( $u ) => $u !== '' && $u !== $uri
			);
			update_post_meta( (int) $pid, '_pressocampus_related', implode( ',', array_values( $parts ) ) );
		}
	}

	/**
	 * Serialize a WP_Post to the JSON export array shape.
	 */
	private function post_to_export_array( WP_Post $post, bool $is_soul ): array {
		$related_raw = (string) get_post_meta( $post->ID, '_pressocampus_related', true );
		$related     = $related_raw !== ''
			? array_map( 'trim', explode( ',', $related_raw ) )
			: array();

		$terms = get_the_terms( $post->ID, PRESSOCAMPUS_TAXONOMY );
		$group = ( is_array( $terms ) && ! empty( $terms ) ) ? $terms[0]->slug : '';

		return array(
			'uri'            => (string) get_post_meta( $post->ID, '_pressocampus_uri', true ),
			'name'           => $post->post_title,
			'content'        => CPT::get_raw_content( $post->ID ),
			'mime_type'      => (string) ( get_post_meta( $post->ID, '_pressocampus_mime_type', true ) ?: 'text/markdown' ),
			'group'          => $group,
			'priority'       => (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' ),
			'confidence'     => (string) ( get_post_meta( $post->ID, '_pressocampus_confidence', true ) ?: 'medium' ),
			'related'        => $related,
			'schema_version' => (string) ( get_post_meta( $post->ID, '_pressocampus_schema_version', true ) ?: '1.0' ),
			'is_soul'        => $is_soul,
			'created_at'     => $post->post_date_gmt,
			'updated_at'     => $post->post_modified_gmt,
		);
	}

	/**
	 * Serialize a WP_Post to Markdown with YAML front matter.
	 */
	private function post_to_markdown( WP_Post $post, bool $is_soul ): string {
		$data    = $this->post_to_export_array( $post, $is_soul );
		$related = implode( ', ', $data['related'] );

		$fm  = "---\n";
		$fm .= "uri: \"{$data['uri']}\"\n";
		$fm .= 'name: ' . $this->yaml_quote( $data['name'] ) . "\n";
		$fm .= "group: {$data['group']}\n";
		$fm .= "priority: {$data['priority']}\n";
		$fm .= "confidence: {$data['confidence']}\n";
		$fm .= "schema_version: \"{$data['schema_version']}\"\n";
		$fm .= 'is_soul: ' . ( $data['is_soul'] ? 'true' : 'false' ) . "\n";
		if ( $related !== '' ) {
			$fm .= 'related: ' . $this->yaml_quote( $related ) . "\n";
		}
		$fm .= "created_at: \"{$data['created_at']}\"\n";
		$fm .= "updated_at: \"{$data['updated_at']}\"\n";
		$fm .= "---\n\n";

		return $fm . $data['content'];
	}

	/**
	 * Wrap a string in YAML double-quotes, escaping inner quotes.
	 */
	private function yaml_quote( string $s ): string {
		return '"' . str_replace( '"', '\\"', $s ) . '"';
	}

	/**
	 * Import memories from a JSON file.
	 */
	private function import_json(
		string $file,
		int $user_id,
		string $host,
		ResourceIndex $index,
		Soul $soul,
		bool $skip
	): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $file );
		if ( $raw === false ) {
			WP_CLI::error( "Cannot read: {$file}" );
		}

		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) ) {
			WP_CLI::error( "Invalid JSON in: {$file}" );
		}

		[ $imported, $skipped ] = $this->upsert_many( $data, $user_id, $host, $index, $soul, $skip );
		WP_CLI::success( "Import complete: {$imported} imported, {$skipped} skipped." );
	}

	/**
	 * Import memories from a directory of .md files.
	 */
	private function import_markdown_folder(
		string $dir,
		int $user_id,
		string $host,
		ResourceIndex $index,
		Soul $soul,
		bool $skip
	): void {
		$files = glob( rtrim( $dir, '/' ) . '/*.md' );
		if ( $files === false || empty( $files ) ) {
			WP_CLI::error( "No .md files found in: {$dir}" );
		}

		$items = array();
		foreach ( $files as $md_file ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw      = (string) file_get_contents( $md_file );
			$fm       = $this->parse_front_matter( $raw );
			$content  = $this->strip_front_matter( $raw );
			$basename = basename( $md_file, '.md' );
			$is_soul  = strtoupper( $basename ) === 'SOUL';

			$items[] = array(
				'uri'            => $fm['uri'] ?? '',
				'name'           => $fm['name'] ?? $basename,
				'content'        => $content,
				'mime_type'      => $fm['mime_type'] ?? 'text/markdown',
				'group'          => $fm['group'] ?? '',
				'priority'       => $fm['priority'] ?? 'normal',
				'confidence'     => $fm['confidence'] ?? 'medium',
				'schema_version' => $fm['schema_version'] ?? '1.0',
				'is_soul'        => $is_soul,
				'related'        => isset( $fm['related'] )
					? array_map( 'trim', explode( ',', $fm['related'] ) )
					: array(),
			);
		}

		[ $imported, $skipped ] = $this->upsert_many( $items, $user_id, $host, $index, $soul, $skip );
		WP_CLI::success( "Import complete: {$imported} imported, {$skipped} skipped." );
	}

	/**
	 * Upsert an array of memory items. Returns [imported, skipped].
	 *
	 * @param array<array<string,mixed>> $items    Memory items to import.
	 * @param int                        $user_id  WordPress user ID to assign memories to.
	 * @param string                     $host     Site hostname for URI namespacing.
	 * @param ResourceIndex              $index    Resource index service instance.
	 * @param Soul                       $soul     Soul service instance.
	 * @param bool                       $skip     When true, skip existing memories instead of overwriting.
	 * @return array{int, int}
	 */
	private function upsert_many(
		array $items,
		int $user_id,
		string $host,
		ResourceIndex $index,
		Soul $soul,
		bool $skip
	): array {
		$imported = 0;
		$skipped  = 0;

		foreach ( $items as $item ) {
			$ok = $this->upsert_memory( $item, $user_id, $host, $index, $soul, $skip );
			$ok ? $imported++ : $skipped++;
		}

		return array( $imported, $skipped );
	}

	/**
	 * Upsert a single memory item. Returns true on success, false on skip/error.
	 *
	 * @param array<string,mixed> $item     Single memory item data.
	 * @param int                 $user_id  WordPress user ID to assign the memory to.
	 * @param string              $host     Site hostname for URI namespacing.
	 * @param ResourceIndex       $index    Resource index service instance.
	 * @param Soul                $soul     Soul service instance.
	 * @param bool                $skip     When true, skip if memory with same URI exists.
	 */
	private function upsert_memory(
		array $item,
		int $user_id,
		string $host,
		ResourceIndex $index,
		Soul $soul,
		bool $skip
	): bool {
		$is_soul = (bool) ( $item['is_soul'] ?? false );
		$content = (string) ( $item['content'] ?? '' );
		$name    = (string) ( $item['name'] ?? '' );
		$group   = (string) ( $item['group'] ?? '' );

		// Rewrite the host portion of the URI to the current site.
		$raw_uri    = (string) ( $item['uri'] ?? '' );
		$target_uri = (string) preg_replace( '#^pressocampus://[^/]+/#', 'pressocampus://' . $host . '/', $raw_uri );

		if ( $is_soul ) {
			$target_uri    = Soul::get_uri( $host );
			$existing_soul = $soul->get_post( $user_id );

			if ( $existing_soul !== null ) {
				if ( ! $skip ) {
					WP_CLI::confirm( 'Overwrite the existing soul for this user?' );
				}
				wp_update_post(
					array(
						'ID'           => $existing_soul->ID,
						'post_content' => $content,
					)
				);
				$index->upsert( $existing_soul->ID, $target_uri, $user_id, $content );
				WP_CLI::line( '  Updated soul.' );
				return true;
			}
		}

		// If URI already indexed, update in place.
		$existing_row = $index->get_by_uri( $target_uri );
		if ( $existing_row !== null ) {
			$post_id = (int) $existing_row['post_id'];
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_title'   => $name,
					'post_content' => $content,
				)
			);
			$index->upsert( $post_id, $target_uri, $user_id, $content );
			WP_CLI::line( "  Updated: {$target_uri}" );
			return true;
		}

		// Create new post.
		$post_id = wp_insert_post(
			array(
				'post_type'    => PRESSOCAMPUS_CPT,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
				'post_title'   => $name,
				'post_content' => $content,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::warning( "Failed to import {$target_uri}: " . $post_id->get_error_message() );
			return false;
		}

		update_post_meta( $post_id, '_pressocampus_uri', $target_uri );
		update_post_meta( $post_id, '_pressocampus_mime_type', $item['mime_type'] ?? 'text/markdown' );
		update_post_meta( $post_id, '_pressocampus_annotation_priority', $item['priority'] ?? 'normal' );
		update_post_meta( $post_id, '_pressocampus_confidence', $item['confidence'] ?? 'medium' );
		update_post_meta( $post_id, '_pressocampus_schema_version', $item['schema_version'] ?? '1.0' );

		if ( ! empty( $item['related'] ) ) {
			update_post_meta( $post_id, '_pressocampus_related', implode( ',', (array) $item['related'] ) );
		}

		if ( $group !== '' ) {
			wp_set_post_terms( $post_id, array( $group ), PRESSOCAMPUS_TAXONOMY );
		}

		$index->upsert( $post_id, $target_uri, $user_id, $content );
		WP_CLI::line( "  Imported: {$target_uri}" );
		return true;
	}

	/**
	 * Parse YAML front matter from a Markdown document.
	 *
	 * Handles simple scalar values only (no nested keys or lists).
	 *
	 * @return array<string, string>
	 */
	private function parse_front_matter( string $raw ): array {
		if ( ! str_starts_with( $raw, '---' ) ) {
			return array();
		}

		$end = strpos( $raw, '---', 3 );
		if ( $end === false ) {
			return array();
		}

		$yaml = substr( $raw, 3, $end - 3 );
		$fm   = array();

		foreach ( explode( "\n", $yaml ) as $line ) {
			$line = trim( $line );
			if ( $line === '' || ! str_contains( $line, ':' ) ) {
				continue;
			}
			[ $key, $value ]    = array_pad( explode( ':', $line, 2 ), 2, '' );
			$fm[ trim( $key ) ] = trim( trim( (string) $value ), '"\'` ' );
		}

		return $fm;
	}

	/**
	 * Remove the YAML front matter block from a Markdown document.
	 */
	private function strip_front_matter( string $raw ): string {
		if ( ! str_starts_with( $raw, '---' ) ) {
			return $raw;
		}

		$end = strpos( $raw, '---', 3 );
		if ( $end === false ) {
			return $raw;
		}

		return ltrim( substr( $raw, $end + 3 ) );
	}
}

WP_CLI::add_command( 'pressocampus', 'Pressocampus_CLI' );
