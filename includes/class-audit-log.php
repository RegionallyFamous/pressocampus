<?php
/**
 * Audit log — persists and queries the pressocampus_audit_log table.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AuditLog {

	private function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'pressocampus_audit_log';
	}

	/**
	 * Compatibility shim called by OAuthServer for OAuth lifecycle events.
	 *
	 * Accepts an associative context array rather than positional parameters.
	 * The events logged here (oauth_client_registered, oauth_authorized,
	 * oauth_token_issued) have no memory URI, so those fields are left empty.
	 *
	 * @param string  $action  Action identifier.
	 * @param mixed[] $context Optional: client_id, client_name, user_id.
	 */
	public function log( string $action, array $context = array() ): void {
		$this->record(
			$action,
			(int) ( $context['user_id'] ?? 0 ),
			(string) ( $context['client_name'] ?? $context['client_id'] ?? '' ),
		);
	}

	/**
	 * Record a single audit log entry.
	 *
	 * @param string $action      e.g. 'remember', 'forget', 'update_memory', 'update_soul',
	 *                            'resources_list', 'resources_read'
	 * @param int    $user_id     WordPress user ID
	 * @param string $client_name OAuth client name
	 * @param string $memory_uri  MCP resource URI (optional)
	 * @param string $memory_name Human-readable memory name (optional)
	 * @param string $context     Free-form context from MCP tool call (truncated to 200 chars)
	 */
	public function record(
		string $action,
		int $user_id,
		string $client_name,
		string $memory_uri = '',
		string $memory_name = '',
		string $context = ''
	): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'user_id'           => $user_id,
				'oauth_client_name' => $client_name,
				'action'            => sanitize_text_field( $action ),
				'memory_uri'        => mb_substr( $memory_uri, 0, 500 ),
				'memory_name'       => mb_substr( $memory_name, 0, 500 ),
				'context'           => mb_substr( $context, 0, 200 ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get paginated audit log entries.
	 *
	 * @param int    $user_id       0 = all users (admin), non-zero = filter to that user
	 * @param string $search        Keyword searched against memory_name + context
	 * @param string $agent         Filter by oauth_client_name
	 * @param string $action_filter Filter by action
	 * @param int    $page          1-based page number
	 * @param int    $per_page      Results per page
	 *
	 * @return array{items: list<array<string,mixed>>, total: int}
	 */
	public function get_entries(
		int $user_id = 0,
		string $search = '',
		string $agent = '',
		string $action_filter = '',
		int $page = 1,
		int $per_page = 50
	): array {
		global $wpdb;

		$table  = $this->table();
		$where  = array( '1=1' );
		$values = array();

		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$values[] = $user_id;
		}

		if ( $search !== '' ) {
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(memory_name LIKE %s OR context LIKE %s)';
			$values[] = $like;
			$values[] = $like;
		}

		if ( $agent !== '' ) {
			$where[]  = 'oauth_client_name = %s';
			$values[] = $agent;
		}

		if ( $action_filter !== '' ) {
			$where[]  = 'action = %s';
			$values[] = $action_filter;
		}

		$where_sql = implode( ' AND ', $where );

		// Count total
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) ( $values ? $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) ) : $wpdb->get_var( $count_sql ) );

		// Items
		$offset     = max( 0, ( $page - 1 ) ) * $per_page;
		$items_sql  = "SELECT id, user_id, oauth_client_name, action, memory_uri, memory_name, context, created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
		$items_args = array_merge( $values, array( $per_page, $offset ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$items_args ), ARRAY_A );

		return array(
			'items' => $rows ?: array(),
			'total' => $total,
		);
	}

	/**
	 * Get distinct OAuth client names for the filter dropdown.
	 *
	 * @param int $user_id 0 = all users
	 * @return list<string>
	 */
	public function get_agent_names( int $user_id = 0 ): array {
		global $wpdb;

		$table = $this->table();

		if ( $user_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT oauth_client_name FROM {$table} WHERE user_id = %d ORDER BY oauth_client_name ASC", $user_id ) );
		} else {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col( "SELECT DISTINCT oauth_client_name FROM {$table} ORDER BY oauth_client_name ASC" );
		}

		return array_filter( array_values( $rows ?: array() ), fn( $n ) => $n !== '' );
	}

	/**
	 * Export log entries as a CSV string.
	 *
	 * @param int $user_id 0 = all users
	 * @param int $days    Number of days to look back
	 * @return string RFC 4180 CSV
	 */
	public function export_csv( int $user_id = 0, int $days = 30 ): string {
		global $wpdb;

		$table  = $this->table();
		$since  = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$where  = array( 'created_at >= %s' );
		$values = array( $since );

		if ( $user_id > 0 ) {
			$where[]  = 'user_id = %d';
			$values[] = $user_id;
		}

		$where_sql = implode( ' AND ', $where );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $where_sql uses safe placeholder strings; ...$values provides matching bindings
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, user_id, oauth_client_name, action, memory_uri, memory_name, context, created_at FROM {$table} WHERE {$where_sql} ORDER BY id DESC", ...$values ), ARRAY_A );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- php://temp is an in-memory stream; WP_Filesystem does not support stream wrappers
		$output  = fopen( 'php://temp', 'r+' );
		$headers = array( 'ID', 'User ID', 'Agent', 'Action', 'Memory URI', 'Memory Name', 'Context', 'Date' );
		fputcsv( $output, $headers );

		foreach ( $rows ?: array() as $row ) {
			fputcsv(
				$output,
				array(
					$row['id'],
					$row['user_id'],
					$row['oauth_client_name'],
					$row['action'],
					$row['memory_uri'],
					$row['memory_name'],
					$row['context'],
					$row['created_at'],
				)
			);
		}

		rewind( $output );
		$csv = stream_get_contents( $output );
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- paired with the php://temp fopen above
		fclose( $output );

		return (string) $csv;
	}

	/**
	 * Delete log entries older than $days days.
	 * Called by WP-Cron (hooked in Plugin::init_hooks).
	 *
	 * @param int $days Retention window (default 90)
	 */
	public function purge_old( int $days = 90 ): void {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table()} WHERE created_at < %s", $cutoff ) );
	}
}
