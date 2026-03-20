<?php
/**
 * MCP Protocol 2025-03-26 endpoint — Streamable HTTP / JSON-RPC 2.0.
 *
 * Registered at: POST /wp-json/pressocampus/v1/mcp
 *
 * Handles:
 *   - Notifications (no `id`): HTTP 202, empty body
 *   - Requests (have `id`):    dispatched to method handlers
 *   - Batch arrays:            mixed notifications + requests
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class MCPEndpoint {

	private const MCP_VERSION = '2025-03-26';

	public function __construct(
		private Auth $auth,
		private CPT $cpt,
		private ResourceIndex $resource_index,
		private Soul $soul,
		private AuditLog $audit_log,
		private Cache $cache
	) {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	// -----------------------------------------------------------------------
	// Route registration
	// -----------------------------------------------------------------------

	public function register_route(): void {
		register_rest_route(
			'pressocampus/v1',
			'/mcp',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			'pressocampus/v1',
			'/mcp',
			array(
				'methods'             => 'OPTIONS',
				'callback'            => array( $this, 'handle_cors_preflight' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	// -----------------------------------------------------------------------
	// Main entry-point
	// -----------------------------------------------------------------------

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$this->set_cors_headers();

		if ( ! Auth::get_current_user_id() ) {
			return $this->error_response(
				null,
				-32001,
				'Unauthorized',
				401,
				array( 'reauth_url' => rest_url( 'pressocampus/v1/oauth/authorize' ) )
			);
		}

		$body = $request->get_json_params();
		if ( ! $body ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

		// Batch request (array of RPC objects).
		if ( is_array( $body ) && array_is_list( $body ) && ! empty( $body ) ) {
			$responses = array();
			foreach ( $body as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$resp = $this->dispatch_single( $item );
				if ( $resp !== null ) {
					$responses[] = $resp;
				}
			}

			if ( empty( $responses ) ) {
				return new \WP_REST_Response( null, 202 );
			}
			return new \WP_REST_Response( $responses, 200 );
		}

		// Single request.
		if ( ! is_array( $body ) ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

		$resp = $this->dispatch_single( $body );
		if ( $resp === null ) {
			return new \WP_REST_Response( null, 202 );
		}
		return new \WP_REST_Response( $resp, 200 );
	}

	// -----------------------------------------------------------------------
	// Dispatcher
	// -----------------------------------------------------------------------

	private function dispatch_single( array $rpc ): ?array {
		$method = $rpc['method'] ?? '';
		$id     = $rpc['id'] ?? null;
		$params = $rpc['params'] ?? array();

		$is_notification = ! array_key_exists( 'id', $rpc );

		$result = match ( $method ) {
			'initialize'                => $this->method_initialize( is_array( $params ) ? $params : array() ),
			'notifications/initialized' => null,
			'ping'                      => array(),
			'resources/list'            => $this->method_resources_list( is_array( $params ) ? $params : array() ),
			'resources/read'            => $this->method_resources_read( is_array( $params ) ? $params : array() ),
			'resources/templates/list'  => $this->method_templates_list( is_array( $params ) ? $params : array() ),
			'tools/list'                => $this->method_tools_list(),
			'tools/call'                => $this->method_tools_call( is_array( $params ) ? $params : array() ),
			default                     => $this->rpc_error( -32601, "Method not found: $method" ),
		};

		// Notifications never get a response.
		if ( $is_notification ) {
			return null;
		}

		// notifications/initialized is always treated as a notification.
		if ( $method === 'notifications/initialized' ) {
			return null;
		}

		if ( $result === null ) {
			return null;
		}

		if ( isset( $result['__rpc_error'] ) ) {
			return array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $result['error'],
			);
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	// -----------------------------------------------------------------------
	// MCP methods
	// -----------------------------------------------------------------------

	private function method_initialize( array $params ): array {
		$user_id     = Auth::get_current_user_id();
		$client_name = Auth::get_current_client_name();
		$host        = Auth::get_site_host();

		// Ensure soul exists — get_snapshot() already creates it, but we
		// replicate the spec pattern for clarity.
		$soul_post = $this->soul->get_post( $user_id );
		if ( ! $soul_post ) {
			$this->soul->create( $user_id, $host );
		}

		$snapshot_data = $this->soul->get_snapshot( $user_id );

		$groups       = $this->resource_index->get_user_groups( $user_id );
		$memory_count = $this->resource_index->get_memory_count( $user_id );

		return array(
			'protocolVersion' => self::MCP_VERSION,
			'serverInfo'      => array(
				'name'    => 'Pressocampus',
				'version' => PRESSOCAMPUS_VERSION,
			),
			'instructions'    => 'Read meta.soul_snapshot before responding — it defines who this person is. If meta.soulStatus is empty, interview them and write their soul before anything else. Remember things proactively; search before remembering; only forget when clearly asked. These memories are a long-term personal archive meant to outlast any AI platform — handle them with the permanence they deserve.',
			'capabilities'    => array(
				'resources' => array(
					'listChanged' => true,
					'subscribe'   => false,
				),
				'tools'     => new \stdClass(),
			),
			'meta'            => array(
				'groups'         => $groups,
				'memoryCount'    => $memory_count,
				'soulStatus'     => $snapshot_data['status'],
				'client_name'    => $client_name ?: 'AI',
				'soul_snapshot'  => $snapshot_data['snapshot'],
				'soul_etag'      => $snapshot_data['etag'],
				'soul_truncated' => $snapshot_data['truncated'],
			),
		);
	}

	private function method_resources_list( array $params ): array {
		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();

		if ( ! $this->auth->check_rate_limit( 'read' ) ) {
			return $this->rpc_error( -32008, 'Rate limit exceeded for reads (60/min)' );
		}

		$this->audit_log->record(
			'resources_list',
			$user_id,
			Auth::get_current_client_name(),
			'',
			'',
			''
		);

		$query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => array( 'publish', 'pressocampus_expired' ),
				'author'         => $user_id,
				'posts_per_page' => -1,
				'no_found_rows'  => true,
			)
		);

		$soul_uri  = Soul::get_uri( $host );
		$index_uri = Soul::get_index_uri( $host );

		$soul_resource  = null;
		$index_resource = null;
		$memories       = array();

		foreach ( $query->posts as $post ) {
			$uri      = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
			$priority = (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' );
			$resource = $this->post_to_resource_item( $post, $uri, $priority );

			if ( $uri === $soul_uri ) {
				$soul_resource = $resource;
			} elseif ( $uri === $index_uri ) {
				$index_resource = $resource;
			} else {
				$memories[] = array(
					'resource'       => $resource,
					'priority_float' => CPT::priority_to_float( $priority ),
				);
			}
		}

		// Sort by priority descending, then by recency descending.
		usort(
			$memories,
			static fn( array $a, array $b ): int =>
			$b['priority_float'] <=> $a['priority_float']
				?: strcmp(
					$b['resource']['updated_at'] ?? '',
					$a['resource']['updated_at'] ?? ''
				)
		);

		$resources = array();
		if ( $soul_resource ) {
			$resources[] = $soul_resource;
		}
		if ( $index_resource ) {
			$resources[] = $index_resource;
		}
		foreach ( $memories as $m ) {
			$resources[] = $m['resource'];
		}

		$this->resource_index->rebuild_if_dirty( $user_id, $host );

		return array( 'resources' => $resources );
	}

	private function post_to_resource_item( \WP_Post $post, string $uri, string $priority ): array {
		return array(
			'uri'         => $uri,
			'name'        => $post->post_title,
			'description' => (string) ( get_post_meta( $post->ID, '_pressocampus_description', true ) ?: '' ),
			'mimeType'    => (string) ( get_post_meta( $post->ID, '_pressocampus_mime_type', true ) ?: 'text/markdown' ),
			'annotations' => array(
				'priority'   => CPT::priority_to_float( $priority ),
				'confidence' => (string) ( get_post_meta( $post->ID, '_pressocampus_confidence', true ) ?: 'medium' ),
			),
			'updated_at'  => $post->post_modified_gmt,
		);
	}

	private function method_resources_read( array $params ): array {
		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$uri     = $params['uri'] ?? '';

		if ( ! $uri ) {
			return $this->rpc_error( -32602, 'Missing required param: uri' );
		}

		if ( ! $this->auth->check_rate_limit( 'read' ) ) {
			return $this->rpc_error( -32008, 'Rate limit exceeded for reads (60/min)' );
		}

		if ( $uri === Soul::get_index_uri( $host ) ) {
			$this->resource_index->rebuild_if_dirty( $user_id, $host );
		}

		$index_entry = $this->resource_index->get_by_uri( $uri );
		if ( ! $index_entry || (int) $index_entry['user_id'] !== $user_id ) {
			return $this->rpc_error( -32002, 'Memory not found', 404 );
		}

		$post_id = (int) $index_entry['post_id'];
		$content = CPT::get_raw_content( $post_id );
		$mime    = (string) ( get_post_meta( $post_id, '_pressocampus_mime_type', true ) ?: 'text/markdown' );
		$related = (string) ( get_post_meta( $post_id, '_pressocampus_related', true ) ?: '' );

		$related_uris = $related
			? array_values( array_filter( array_map( 'trim', explode( ',', $related ) ) ) )
			: array();

		$this->audit_log->record(
			'resources_read',
			$user_id,
			Auth::get_current_client_name(),
			$uri,
			(string) get_the_title( $post_id ),
			''
		);

		$etag = $index_entry['content_hash'];
		if ( $etag ) {
			header( 'ETag: "' . $etag . '"' );
			header( 'Cache-Control: private, max-age=300' );
		}

		return array(
			'contents'    => array(
				array(
					'uri'      => $uri,
					'text'     => $content,
					'mimeType' => $mime,
				),
			),
			'annotations' => array(
				'confidence' => (string) ( get_post_meta( $post_id, '_pressocampus_confidence', true ) ?: 'medium' ),
				'priority'   => CPT::priority_to_float(
					(string) ( get_post_meta( $post_id, '_pressocampus_annotation_priority', true ) ?: 'normal' )
				),
				'related'    => $related_uris,
				'etag'       => $etag,
			),
		);
	}

	private function method_templates_list( array $params ): array {
		$host = Auth::get_site_host();
		return array(
			'resourceTemplates' => array(
				array(
					'uriTemplate' => "pressocampus://{$host}/memory/{uuid}",
					'name'        => 'Memory',
					'description' => 'A single stored memory',
					'mimeType'    => 'text/markdown',
				),
			),
		);
	}

	private function method_tools_list(): array {
		return array(
			'tools' => array(
				array(
					'name'        => 'remember',
					'description' => "Store something permanently. Use when the user states a preference, shares a personal fact, describes a decision, or asks you to remember something. Don't remember questions, greetings, or casual conversation.",
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'content'    => array(
								'type'        => 'string',
								'description' => 'The content to remember',
							),
							'name'       => array(
								'type'        => 'string',
								'description' => 'Display name. Auto-generated from first 60 chars if omitted.',
							),
							'group'      => array(
								'type'        => 'string',
								'description' => 'Group/category. Use existing groups from initialize meta.groups when possible.',
							),
							'related'    => array(
								'type'        => 'array',
								'items'       => array( 'type' => 'string' ),
								'description' => 'URIs of related memories',
							),
							'priority'   => array(
								'type'    => 'string',
								'enum'    => array( 'critical', 'important', 'normal', 'low' ),
								'default' => 'normal',
							),
							'confidence' => array(
								'type'    => 'string',
								'enum'    => array( 'high', 'medium', 'low' ),
								'default' => 'medium',
							),
							'context'    => array(
								'type'        => 'string',
								'description' => 'Why you are storing this (shown in History). Max 200 chars.',
							),
						),
						'required'   => array( 'content' ),
					),
				),
				array(
					'name'        => 'forget',
					'description' => 'Permanently delete a memory. Only call this when the user explicitly asks to forget something. This is irreversible.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'uri'     => array(
								'type'        => 'string',
								'description' => 'The URI of the memory to delete',
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Why you are deleting this',
							),
						),
						'required'   => array( 'uri' ),
					),
				),
				array(
					'name'        => 'update_memory',
					'description' => 'Update the content of an existing memory. Use update_soul or update_soul_section for the soul.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'uri'     => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
							'etag'    => array(
								'type'        => 'string',
								'description' => 'Optional ETag from resources/read for optimistic concurrency. Returns 409 if stale.',
							),
							'context' => array( 'type' => 'string' ),
						),
						'required'   => array( 'uri', 'content' ),
					),
				),
				array(
					'name'        => 'update_soul',
					'description' => "Update the user's soul — their persistent identity and values that follow them across all AI platforms. Prefer update_soul_section for targeted changes. Use this only for full restructuring. Creates the soul if it doesn't exist.",
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'content' => array(
								'type'        => 'string',
								'description' => 'The full updated soul content (Markdown)',
							),
							'etag'    => array(
								'type'        => 'string',
								'description' => 'Optional ETag for concurrency check',
							),
							'context' => array( 'type' => 'string' ),
						),
						'required'   => array( 'content' ),
					),
				),
				array(
					'name'        => 'update_soul_section',
					'description' => 'Update one section of the soul. Prefer this over update_soul for any targeted change — safer, faster, less risk of overwriting other sections.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'section' => array(
								'type'        => 'string',
								'description' => 'The ## Heading text, e.g. "How I Communicate"',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'New section body',
							),
							'context' => array( 'type' => 'string' ),
						),
						'required'   => array( 'section', 'content' ),
					),
				),
				array(
					'name'        => 'search_memory',
					'description' => 'Search memories by keyword. Call this when the user asks a question that might be answered by stored memories.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'query'   => array( 'type' => 'string' ),
							'group'   => array(
								'type'        => 'string',
								'description' => 'Optional group filter',
							),
							'limit'   => array(
								'type'    => 'integer',
								'default' => 10,
							),
							'context' => array( 'type' => 'string' ),
						),
						'required'   => array( 'query' ),
					),
				),
			),
		);
	}

	private function method_tools_call( array $params ): array {
		$tool_name = $params['name'] ?? '';
		$args      = $params['arguments'] ?? array();

		if ( ! is_array( $args ) ) {
			$args = array();
		}

		return match ( $tool_name ) {
			'remember'            => $this->tool_remember( $args ),
			'forget'              => $this->tool_forget( $args ),
			'update_memory'       => $this->tool_update_memory( $args ),
			'update_soul'         => $this->tool_update_soul( $args ),
			'update_soul_section' => $this->tool_update_soul_section( $args ),
			'search_memory'       => $this->tool_search_memory( $args ),
			default               => array(
				'isError' => true,
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode(
							array(
								'code'    => 'tool_not_found',
								'message' => "Unknown tool: $tool_name",
							)
						),
					),
				),
			),
		};
	}

	// -----------------------------------------------------------------------
	// Tools
	// -----------------------------------------------------------------------

	private function tool_remember( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Write rate limit reached (30/min). Please wait a moment and try again.' );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$content = $args['content'] ?? '';
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $content ) {
			return $this->tool_error( 'missing_content', 'Content is required.' );
		}

		$settings = get_option( 'pressocampus_settings', array() );
		$max_size = (int) ( $settings['max_content_size'] ?? 524288 );
		if ( mb_strlen( $content, 'UTF-8' ) > $max_size ) {
			return $this->tool_error( 'content_too_large', "Content exceeds maximum size of {$max_size} bytes." );
		}

		$count_limit = (int) ( $settings['memory_count_limit'] ?? 1000 );
		if ( $this->resource_index->get_memory_count( $user_id ) >= $count_limit ) {
			return $this->tool_error( 'memory_limit_reached', "You've reached the memory limit of {$count_limit}. Use forget to remove some memories first." );
		}

		// Server-side dedup + contradiction check.
		$search_results = $this->resource_index->search( $content, $user_id, null, 3 );

		$possible_duplicate     = null;
		$possible_contradiction = null;

		if ( ! empty( $search_results ) ) {
			$content_hash = md5( $content );
			foreach ( $search_results as $result ) {
				$index_entry = $this->resource_index->get_by_uri( $result['uri'] );
				if ( $index_entry && $index_entry['content_hash'] === $content_hash ) {
					return $this->tool_success(
						array(
							'uri'  => $result['uri'],
							'name' => $result['name'],
							'note' => 'This memory already exists (exact duplicate). No new memory created.',
						)
					);
				}
				if ( ! $possible_contradiction ) {
					similar_text( $content, (string) ( $result['excerpt'] ?? '' ), $pct );
					if ( $pct > 50 ) {
						$possible_contradiction = array(
							'uri'        => $result['uri'],
							'name'       => $result['name'],
							'excerpt'    => $result['excerpt'],
							'updated_at' => $result['updated_at'] ?? '',
						);
					}
				}
				if ( ! $possible_duplicate ) {
					$possible_duplicate = array(
						'uri'     => $result['uri'],
						'name'    => $result['name'],
						'excerpt' => $result['excerpt'],
					);
				}
			}
		}

		$name = $args['name'] ?? '';
		if ( ! $name ) {
			$name = mb_substr( wp_strip_all_tags( $content ), 0, 60, 'UTF-8' );
			$name = preg_replace( '/\s+/', ' ', trim( $name ) ) ?? $name;
		}

		$uri        = CPT::generate_uri( $host );
		$priority   = in_array( $args['priority'] ?? '', array( 'critical', 'important', 'normal', 'low' ), true ) ? $args['priority'] : 'normal';
		$confidence = in_array( $args['confidence'] ?? '', array( 'high', 'medium', 'low' ), true ) ? $args['confidence'] : 'medium';
		$group      = sanitize_text_field( $args['group'] ?? '' );
		$related    = is_array( $args['related'] ?? null )
			? implode( ',', array_map( 'sanitize_text_field', $args['related'] ) )
			: '';

		$post_id = wp_insert_post(
			array(
				'post_type'    => PRESSOCAMPUS_CPT,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => $content,
				'post_author'  => $user_id,
			)
		);

		if ( is_wp_error( $post_id ) ) {
			return $this->tool_error( 'insert_failed', $post_id->get_error_message() );
		}

		update_post_meta( $post_id, '_pressocampus_uri', $uri );
		update_post_meta( $post_id, '_pressocampus_mime_type', 'text/markdown' );
		update_post_meta( $post_id, '_pressocampus_annotation_priority', $priority );
		update_post_meta( $post_id, '_pressocampus_confidence', $confidence );
		update_post_meta( $post_id, '_pressocampus_schema_version', '1.0' );

		if ( $related ) {
			update_post_meta( $post_id, '_pressocampus_related', $related );
		}
		if ( $group ) {
			wp_set_object_terms( $post_id, $group, PRESSOCAMPUS_TAXONOMY );
		}

		$this->resource_index->upsert( $post_id, $uri, $user_id, $content );
		$this->audit_log->record( 'remember', $user_id, Auth::get_current_client_name(), $uri, $name, $context );

		do_action( 'pressocampus_memory_changed', $post_id, $user_id );
		$this->resource_index->mark_dirty( $user_id );

		$result_data = array(
			'uri'  => $uri,
			'name' => $name,
		);
		if ( $possible_duplicate ) {
			$result_data['possible_duplicate'] = $possible_duplicate;
		}
		if ( $possible_contradiction ) {
			$result_data['possible_contradiction'] = $possible_contradiction;
		}

		return $this->tool_success( $result_data );
	}

	private function tool_forget( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Write rate limit reached (30/min). Please wait a moment.' );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$uri     = $args['uri'] ?? '';
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $uri ) {
			return $this->tool_error( 'missing_uri', 'URI is required.' );
		}

		if ( Soul::is_protected( $uri, $host ) ) {
			return $this->tool_error( 'soul_protected', 'Your soul and memory index are protected and cannot be deleted. Use update_soul_section to edit the soul instead.' );
		}

		$index_entry = $this->resource_index->get_by_uri( $uri );
		if ( ! $index_entry || (int) $index_entry['user_id'] !== $user_id ) {
			return $this->tool_error( 'not_found', 'Memory not found.' );
		}

		$post_id    = (int) $index_entry['post_id'];
		$post_title = (string) get_the_title( $post_id );

		$this->resource_index->rewrite_related_uri( $uri, '' );

		$revisions = wp_get_post_revisions( $post_id );
		foreach ( $revisions as $revision ) {
			wp_delete_post_revision( $revision->ID );
		}

		wp_delete_post( $post_id, true );
		$this->resource_index->delete_by_post_id( $post_id );

		$this->audit_log->record( 'forget', $user_id, Auth::get_current_client_name(), $uri, $post_title, $context );

		do_action( 'pressocampus_memory_changed', $post_id, $user_id );
		$this->resource_index->mark_dirty( $user_id );

		return $this->tool_success(
			array(
				'uri'     => $uri,
				'name'    => $post_title,
				'deleted' => true,
			)
		);
	}

	private function tool_update_memory( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Write rate limit reached (30/min).' );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$uri     = $args['uri'] ?? '';
		$content = $args['content'] ?? '';
		$etag    = isset( $args['etag'] ) ? (string) $args['etag'] : null;
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $uri ) {
			return $this->tool_error( 'missing_uri', 'URI is required.' );
		}
		if ( ! $content ) {
			return $this->tool_error( 'missing_content', 'Content is required.' );
		}

		if ( Soul::is_protected( $uri, $host ) ) {
			return $this->tool_error( 'soul_protected', 'Use update_soul or update_soul_section to update the soul.' );
		}

		$index_entry = $this->resource_index->get_by_uri( $uri );
		if ( ! $index_entry || (int) $index_entry['user_id'] !== $user_id ) {
			return $this->tool_error( 'not_found', 'Memory not found.' );
		}

		$post_id = (int) $index_entry['post_id'];

		if ( $etag !== null ) {
			$current_hash = $index_entry['content_hash'];
			if ( $etag !== $current_hash ) {
				return $this->tool_error( 'etag_conflict', 'Memory was modified since you last read it (ETag mismatch). Re-read it with resources/read and retry.', 409 );
			}
		}

		$update_result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $update_result ) ) {
			return $this->tool_error( 'update_failed', $update_result->get_error_message() );
		}

		$this->resource_index->upsert( $post_id, $uri, $user_id, $content );
		$new_hash = md5( $content );

		$this->audit_log->record( 'update_memory', $user_id, Auth::get_current_client_name(), $uri, (string) get_the_title( $post_id ), $context );
		do_action( 'pressocampus_memory_changed', $post_id, $user_id );
		$this->resource_index->mark_dirty( $user_id );

		return $this->tool_success(
			array(
				'uri'  => $uri,
				'etag' => $new_hash,
			)
		);
	}

	private function tool_update_soul( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Write rate limit reached (30/min).' );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$content = $args['content'] ?? '';
		$etag    = isset( $args['etag'] ) ? (string) $args['etag'] : null;
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $content ) {
			return $this->tool_error( 'missing_content', 'Content is required.' );
		}

		$result = $this->soul->update( $user_id, $content, $host, $etag );

		if ( $result['error'] ?? false ) {
			return $this->tool_error(
				(string) ( $result['code'] ?? 'update_failed' ),
				(string) ( $result['message'] ?? 'Update failed.' ),
				(int) ( $result['status'] ?? 400 )
			);
		}

		$this->audit_log->record( 'update_soul', $user_id, Auth::get_current_client_name(), (string) $result['uri'], 'My Soul', $context );
		$this->resource_index->mark_dirty( $user_id );

		return $this->tool_success(
			array(
				'uri'  => $result['uri'],
				'etag' => $result['etag'],
			)
		);
	}

	private function tool_update_soul_section( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Write rate limit reached (30/min).' );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$section = $args['section'] ?? '';
		$content = $args['content'] ?? '';
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $section ) {
			return $this->tool_error( 'missing_section', 'Section heading is required.' );
		}
		if ( ! $content ) {
			return $this->tool_error( 'missing_content', 'Content is required.' );
		}

		$result = $this->soul->update_section( $user_id, $section, $content, $host );

		if ( $result['error'] ?? false ) {
			return $this->tool_error(
				(string) ( $result['code'] ?? 'update_failed' ),
				(string) ( $result['message'] ?? 'Update failed.' )
			);
		}

		$this->audit_log->record( 'update_soul_section', $user_id, Auth::get_current_client_name(), (string) $result['uri'], "My Soul → {$section}", $context );
		$this->resource_index->mark_dirty( $user_id );

		return $this->tool_success(
			array(
				'uri'     => $result['uri'],
				'etag'    => $result['etag'],
				'section' => $section,
			)
		);
	}

	private function tool_search_memory( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'read' ) ) {
			return $this->tool_error( 'rate_limit_exceeded', 'Read rate limit reached (60/min).' );
		}

		$user_id = Auth::get_current_user_id();
		$query   = $args['query'] ?? '';
		$group   = isset( $args['group'] ) && $args['group'] !== '' ? (string) $args['group'] : null;
		$limit   = min( (int) ( $args['limit'] ?? 10 ), 50 );
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $query ) {
			return $this->tool_error( 'missing_query', 'Query is required.' );
		}

		$results = $this->resource_index->search( $query, $user_id, $group, $limit );

		$this->audit_log->record( 'search_memory', $user_id, Auth::get_current_client_name(), '', $query, $context );

		return $this->tool_success(
			array(
				'results' => $results,
				'count'   => count( $results ),
			)
		);
	}

	// -----------------------------------------------------------------------
	// Response helpers
	// -----------------------------------------------------------------------

	private function tool_success( array $data ): array {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $data ),
				),
			),
		);
	}

	private function tool_error( string $code, string $message, int $status = 400 ): array {
		return array(
			'isError' => true,
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode(
						array(
							'code'    => $code,
							'message' => $message,
						)
					),
				),
			),
		);
	}

	private function rpc_error( int $code, string $message, int $http_status = 400 ): array {
		return array(
			'__rpc_error' => true,
			'error'       => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	private function error_response( ?int $id, int $code, string $message, int $http_status = 400, array $data = array() ): \WP_REST_Response {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( $data ) {
			$error['data'] = $data;
		}

		return new \WP_REST_Response(
			array(
				'jsonrpc' => '2.0',
				'id'      => $id,
				'error'   => $error,
			),
			$http_status
		);
	}

	// -----------------------------------------------------------------------
	// CORS
	// -----------------------------------------------------------------------

	private function set_cors_headers(): void {
		$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

		if ( $origin !== '' ) {
			$settings        = get_option( 'pressocampus_settings', array() );
			$allowed_origins = array_filter(
				array_map( 'trim', explode( "\n", $settings['cors_origins'] ?? '' ) )
			);

			$site_origin    = wp_parse_url( home_url(), PHP_URL_SCHEME ) . '://' . wp_parse_url( home_url(), PHP_URL_HOST );
			$always_allowed = array( $site_origin );

			if ( in_array( $origin, $allowed_origins, true ) || in_array( $origin, $always_allowed, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Credentials: true' );
				header( 'Vary: Origin' );
			}
		}

		header( 'Access-Control-Allow-Methods: POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type' );
		header( 'Content-Type: application/json; charset=utf-8' );
	}

	public function handle_cors_preflight( \WP_REST_Request $request ): \WP_REST_Response {
		$this->set_cors_headers();
		return new \WP_REST_Response( null, 204 );
	}
}
