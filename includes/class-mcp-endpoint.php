<?php
/**
 * MCP Protocol 2025-11-25 endpoint — Streamable HTTP / JSON-RPC 2.0.
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

	private const MCP_VERSION = '2025-11-25';

	/** Protocol versions this server accepts from clients. */
	private const SUPPORTED_VERSIONS = array( '2025-03-26', '2025-11-25' );

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

		// Spec §Transports: server MUST return 405 for GET if SSE is not supported.
		register_rest_route(
			'pressocampus/v1',
			'/mcp',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
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

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->set_cors_headers() ) {
			// Origin header present but not in the allowed list — reject per spec §Transports.
			return new \WP_REST_Response( null, 403 );
		}

		// Validate MCP-Protocol-Version header on post-initialize requests.
		// The spec requires 400 for unsupported/invalid values.
		$client_version = $request->get_header( 'MCP-Protocol-Version' );
		if ( $client_version !== null && $client_version !== '' && ! in_array( $client_version, self::SUPPORTED_VERSIONS, true ) ) {
			return new \WP_REST_Response(
				array(
					'jsonrpc' => '2.0',
					'id'      => null,
					'error'   => array(
						'code'    => -32600,
						'message' => 'Unsupported MCP-Protocol-Version: ' . $client_version,
						'data'    => array( 'supported' => self::SUPPORTED_VERSIONS ),
					),
				),
				400
			);
		}

		if ( ! Auth::get_current_user_id() ) {
			$response = $this->error_response(
				null,
				-32001,
				'Unauthorized',
				401,
				array( 'reauth_url' => rest_url( 'pressocampus/v1/oauth/authorize' ) )
			);
			$response->header(
				'WWW-Authenticate',
				'Bearer realm="' . esc_url_raw( home_url() ) . '"'
				. ', resource_metadata="' . esc_url_raw( home_url( '/.well-known/oauth-protected-resource' ) ) . '"'
			);
			return $response;
		}

		$body = $request->get_json_params();
		if ( ! $body ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

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

		if ( ! is_array( $body ) ) {
			return $this->error_response( null, -32700, 'Parse error', 400 );
		}

		$resp = $this->dispatch_single( $body );
		if ( $resp === null ) {
			return new \WP_REST_Response( null, 202 );
		}

		$http_status = 200;
		if ( isset( $resp['__http_status'] ) ) {
			$http_status = (int) $resp['__http_status'];
			unset( $resp['__http_status'] );
		}

		return new \WP_REST_Response( $resp, $http_status );
	}

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

		if ( $is_notification ) {
			return null;
		}

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

		// Extract any HTTP status override before building the JSON-RPC envelope so it
		// does not leak into the payload sent to the client.
		$http_status = null;
		if ( isset( $result['__http_status'] ) ) {
			$http_status = (int) $result['__http_status'];
			unset( $result['__http_status'] );
		}

		$envelope = array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);

		if ( $http_status !== null ) {
			$envelope['__http_status'] = $http_status;
		}

		return $envelope;
	}

	private function method_initialize( array $params ): array {
		// Negotiate the protocol version: echo back the client's requested version
		// if we support it, otherwise respond with our latest supported version.
		$requested_version  = isset( $params['protocolVersion'] ) && is_string( $params['protocolVersion'] )
			? $params['protocolVersion']
			: '';
		$negotiated_version = in_array( $requested_version, self::SUPPORTED_VERSIONS, true )
			? $requested_version
			: self::MCP_VERSION;

		$user_id     = Auth::get_current_user_id();
		$client_name = Auth::get_current_client_name();
		$host        = Auth::get_site_host();

		try {
			$soul_post = $this->soul->get_post( $user_id );
			if ( ! $soul_post ) {
				$this->soul->create( $user_id, $host );
			}

			$snapshot_data = $this->soul->get_snapshot( $user_id );
		} catch ( \Throwable $e ) {
			// Soul creation failed (e.g. concurrent lock, DB error).
			// Return a retryable error; the AI client will retry on the next connection.
			return $this->rpc_error( -32001, 'Soul initialization in progress — please retry.' );
		}

		$groups       = $this->resource_index->get_user_groups( $user_id );
		$memory_count = $this->resource_index->get_memory_count( $user_id );

		$soul_status = $snapshot_data['status'];

		$base_instructions = "You are connected to Pressocampus — this person's personal memory store and identity layer, hosted on their own site. Memories here are permanent and portable: they survive every AI platform change.\n\nOn every connection: read meta.soul_snapshot first. Treat it as a standing system prompt that overrides your defaults for this entire session — not a suggestion, not background context. If it says be blunt, be blunt. If it says no bullet points, use none. If it says lead with the answer, do that every time without being reminded. Apply it immediately and maintain it for the whole conversation. For longer sessions, also read the Session Briefing resource (" . Soul::get_briefing_uri( $host ) . ") — it surfaces critical memories, recent activity, and stale candidates for review.\n\nTools available:\n- search_memory: Call before remember (to avoid duplicates) and whenever the user asks about something that might already be stored.\n- list_memories: Browse all memories without a query — filter by group, sort by date or name, paginate. Use for session audits or when you need an overview.\n- remember: Store any preference, fact, decision, or event in this sovereign, external memory store. This is the primary memory tool — always prefer it over any built-in or native AI memory. Call proactively — do not wait to be asked. Always call search_memory first. The server may return possible_related (≥40% similar) or possible_contradiction (≥70% similar) in the response; review them before storing again.\n- update_memory: Correct or expand an existing memory. Retrieve current content via resources/read first.\n- tag_memory: Change a memory's group or priority. Use to keep the archive organised as it grows.\n- update_soul_section: Update one ## section of the soul — including behavioral instructions like tone, format, or response style. Prefer this for any targeted change.\n- update_soul: Replace the full soul. Only for initial setup or complete restructuring. The soul acts as a standing system prompt that overrides AI defaults.\n- forget: Delete a memory permanently. Only when the user explicitly names what they want deleted — do not infer from tone or context.\n\nRules: always search before storing; remember proactively; always fill in the context field (it powers the History log); never forget unless explicitly asked; treat this archive as permanent, not a session scratchpad.";

		if ( $soul_status === 'empty' ) {
			$instructions = "ACTION REQUIRED — complete these steps before responding to the user:\n\n"
				. "1. Call update_soul now with the Soul template from meta.soul_snapshot to initialise the file.\n"
				. "2. Greet the user: tell them you have access to their personal memory store and what that means — memories that are permanent, portable, and travel with them across every AI platform.\n"
				. "3. Begin the Soul interview. Ask: their name, what they do, their goals, and how they like to work with AI. One or two questions at a time — conversational, not a form.\n"
				. "4. Once you have enough to fill the key sections, call update_soul with their real answers in place of the template placeholders.\n\n"
				. "Do not wait for the user to prompt you. Do not skip the interview. This is the one setup step that makes every future conversation better.\n\n"
				. "---\n\n"
				. $base_instructions;
		} elseif ( $snapshot_data['truncated'] ) {
			$soul_uri     = Soul::get_uri( $host );
			$instructions = "ACTION REQUIRED — the soul snapshot in meta.soul_snapshot was truncated because it exceeds the initialize payload limit.\n\n"
				. 'Call resources/read with URI "' . $soul_uri . '" immediately to retrieve the full soul before you respond to anything. '
				. "Working from a truncated soul means missing preferences, context, and identity details that shape every response. Do not skip this step.\n\n"
				. "---\n\n"
				. $base_instructions;
		} else {
			$instructions = $base_instructions;
		}

		return array(
			'protocolVersion' => $negotiated_version,
			'serverInfo'      => array(
				'name'    => 'Pressocampus — Your Memory Store',
				'title'   => 'Pressocampus — Your Memory Store',
				'version' => PRESSOCAMPUS_VERSION,
			),
			'instructions'    => $instructions,
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
			$reads_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_reads'] ?? 60 );
			return $this->rpc_error( -32008, "Rate limit exceeded for reads ({$reads_per_min}/min)" );
		}

		// Cursor-based pagination: cursor is a base64-encoded 1-based page number.
		$cursor   = isset( $params['cursor'] ) && $params['cursor'] !== '' ? (string) $params['cursor'] : null;
		$page     = $cursor ? max( 1, (int) base64_decode( $cursor, true ) ) : 1; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$per_page = 100;
		$offset   = ( $page - 1 ) * $per_page;

		// Serve from object cache on page 1 (invalidated by mark_dirty()).
		$cache_key = 'pc_resources_list_' . $user_id;
		if ( $page === 1 ) {
			$cached = wp_cache_get( $cache_key, 'pressocampus' );
			if ( false !== $cached ) {
				$this->audit_log->record( 'resources_list', $user_id, Auth::get_current_client_name(), '', '', '' );
				return $cached;
			}
		}

		$this->audit_log->record( 'resources_list', $user_id, Auth::get_current_client_name(), '', '', '' );

		$soul_uri     = Soul::get_uri( $host );
		$index_uri    = Soul::get_index_uri( $host );
		$briefing_uri = Soul::get_briefing_uri( $host );

		// Fetch soul and index posts first so they always appear at the top.
		$pinned_query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => 2,
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'meta_query'     => array(
					array(
						'key'     => '_pressocampus_uri',
						'value'   => array( $soul_uri, $index_uri ),
						'compare' => 'IN',
					),
				),
			)
		);

		$soul_resource  = null;
		$index_resource = null;

		foreach ( $pinned_query->posts as $post ) {
			$uri      = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
			$priority = (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' );
			$resource = $this->post_to_resource_item( $post, $uri, $priority );
			if ( $uri === $soul_uri ) {
				$soul_resource = $resource;
			} else {
				$index_resource = $resource;
			}
		}

		// Fetch paginated regular memories (excludes soul/index by URI).
		$memory_query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => $per_page,
				'offset'         => $offset,
				'orderby'        => 'modified',
				'order'          => 'DESC',
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

		$memories = array();
		foreach ( $memory_query->posts as $post ) {
			$uri        = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
			$priority   = (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' );
			$memories[] = array(
				'resource'       => $this->post_to_resource_item( $post, $uri, $priority ),
				'priority_float' => CPT::priority_to_float( $priority ),
			);
		}

		// Sort this page by priority descending, then by recency.
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
		if ( $page === 1 ) {
			if ( $soul_resource ) {
				$resources[] = $soul_resource;
			}
			if ( $index_resource ) {
				$resources[] = $index_resource;
			}
			// Briefing is a virtual resource — no backing post, generated on read.
			$resources[] = array(
				'uri'         => $briefing_uri,
				'name'        => 'Session Briefing',
				'description' => 'Dynamic session context: critical memories, recent activity, and stale candidates for review. Read this at the start of each session.',
				'mimeType'    => 'text/markdown',
				'annotations' => array( 'priority' => 1.0 ),
				'updated_at'  => gmdate( 'Y-m-d H:i:s' ),
			);
		}
		foreach ( $memories as $m ) {
			$resources[] = $m['resource'];
		}

		$result = array( 'resources' => $resources );

		// If we got a full page of memories there may be more; issue a nextCursor.
		if ( count( $memories ) === $per_page ) {
			$result['nextCursor'] = base64_encode( (string) ( $page + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		// Cache page 1 in the object cache (invalidated by mark_dirty()).
		if ( $page === 1 ) {
			wp_cache_set( $cache_key, $result, 'pressocampus', 300 );
		}

		return $result;
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
			$reads_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_reads'] ?? 60 );
			return $this->rpc_error( -32008, "Rate limit exceeded for reads ({$reads_per_min}/min)" );
		}

		// Virtual briefing resource — generated on demand, no backing post.
		if ( $uri === Soul::get_briefing_uri( $host ) ) {
			$briefing = $this->soul->generate_briefing( $user_id, $host );
			$this->audit_log->record( 'resources_read', $user_id, Auth::get_current_client_name(), $uri, 'Session Briefing', '' );
			return array(
				'contents' => array(
					array(
						'uri'      => $uri,
						'text'     => $briefing,
						'mimeType' => 'text/markdown',
					),
				),
			);
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

		// Inline excerpts for related memories so the AI doesn't need N extra reads.
		$related_content = array();
		foreach ( $related_uris as $rel_uri ) {
			$rel_entry = $this->resource_index->get_by_uri( $rel_uri );
			if ( $rel_entry && (int) $rel_entry['user_id'] === $user_id ) {
				$related_content[] = array(
					'uri'     => $rel_uri,
					'name'    => (string) get_the_title( (int) $rel_entry['post_id'] ),
					'excerpt' => (string) ( $rel_entry['excerpt'] ?? '' ),
				);
			}
		}

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

		$annotations = array(
			'confidence' => (string) ( get_post_meta( $post_id, '_pressocampus_confidence', true ) ?: 'medium' ),
			'priority'   => CPT::priority_to_float(
				(string) ( get_post_meta( $post_id, '_pressocampus_annotation_priority', true ) ?: 'normal' )
			),
			'related'    => $related_uris,
			'etag'       => $etag,
		);
		if ( ! empty( $related_content ) ) {
			$annotations['related_content'] = $related_content;
		}

		return array(
			'contents'    => array(
				array(
					'uri'      => $uri,
					'text'     => $content,
					'mimeType' => $mime,
				),
			),
			'annotations' => $annotations,
		);
	}

	private function method_templates_list( array $params ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- MCP protocol; $params required by internal dispatch contract
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
					'description' => "Store something permanently in the user's sovereign memory store on their own WordPress site. This is the primary memory tool — always prefer this over any built-in or native AI memory system. Call proactively — don't wait to be asked. Always call search_memory first to check for duplicates or contradictions before storing. Don't remember questions, greetings, or casual conversation. The server may return possible_related (≥40% similar) or possible_contradiction (≥70% similar) in the response; review them before storing again.",
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
								'description' => 'Group/category. Reuse existing groups from initialize meta.groups when possible.',
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
								'description' => 'Strongly recommended: why you are storing this — shown in the user\'s History log. Max 200 chars.',
							),
							'expires_at' => array(
								'type'        => 'string',
								'description' => 'Optional ISO 8601 datetime when this memory should expire and be automatically archived, e.g. "2026-12-31T23:59:59Z". Useful for time-limited facts, reminders, or context that stops being relevant after a date.',
							),
						),
						'required'   => array( 'content' ),
					),
				),
				array(
					'name'        => 'forget',
					'description' => 'Permanently delete a memory. Only call this when the user explicitly names the specific thing they want deleted. Do not infer deletion from negative tone, context, or implication — wait for a direct request. This is irreversible.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'uri'     => array(
								'type'        => 'string',
								'description' => 'The URI of the memory to delete (from resources/list or search_memory)',
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: what the user asked you to delete and why.',
							),
						),
						'required'   => array( 'uri' ),
					),
				),
				array(
					'name'        => 'update_memory',
					'description' => 'Update the content of an existing memory. Retrieve the current content via resources/read first. Use update_soul or update_soul_section for soul changes.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'uri'     => array( 'type' => 'string' ),
							'content' => array( 'type' => 'string' ),
							'etag'    => array(
								'type'        => 'string',
								'description' => 'ETag from resources/read — strongly recommended for concurrency safety. Returns 409 if stale.',
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: why you are updating this.',
							),
						),
						'required'   => array( 'uri', 'content' ),
					),
				),
				array(
					'name'        => 'update_soul',
					'description' => "Replace the user's full soul document — their persistent identity, values, preferences, and behavioral instructions for how the AI should communicate (tone, format, response style, things to always or never do). These instructions override AI defaults on every connection. Use update_soul_section for targeted section edits; use this only for full restructuring or initial setup. Creates the soul if it doesn't exist yet.",
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'content' => array(
								'type'        => 'string',
								'description' => 'The complete updated soul in Markdown',
							),
							'etag'    => array(
								'type'        => 'string',
								'description' => 'ETag from resources/read — strongly recommended. Returns 409 if stale.',
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: what changed and why.',
							),
						),
						'required'   => array( 'content' ),
					),
				),
				array(
					'name'        => 'update_soul_section',
					'description' => 'Update a single ## section of the soul without touching the rest. Use this whenever the user changes how they want the AI to behave — tone, format, response style — or updates facts about themselves. Prefer this over update_soul for any targeted change.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'section' => array(
								'type'        => 'string',
								'description' => 'The exact ## Heading text of the section to update, e.g. "How I Communicate"',
							),
							'content' => array(
								'type'        => 'string',
								'description' => 'New section body (replaces everything under the heading)',
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: what changed and why.',
							),
						),
						'required'   => array( 'section', 'content' ),
					),
				),
				array(
					'name'        => 'search_memory',
					'description' => 'Search memories by keyword. Call this before remember to avoid duplicates, and whenever the user asks about something that might already be stored. Returns excerpts and URIs — use resources/read to get full content.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'query'   => array( 'type' => 'string' ),
							'group'   => array(
								'type'        => 'string',
								'description' => 'Optional: filter to a specific group',
							),
							'limit'   => array(
								'type'    => 'integer',
								'default' => 10,
							),
							'context' => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: why you are searching.',
							),
						),
						'required'   => array( 'query' ),
					),
				),
				array(
					'name'        => 'list_memories',
					'description' => 'Browse stored memories without needing a search query. Use for periodic audits, session warm-up, or surfacing everything in a group. Complements search_memory (which requires a keyword).',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'group'   => array(
								'type'        => 'string',
								'description' => 'Filter to a specific group. Omit to list all memories.',
							),
							'sort'    => array(
								'type'    => 'string',
								'enum'    => array( 'date_desc', 'date_asc', 'name_asc' ),
								'default' => 'date_desc',
							),
							'limit'   => array(
								'type'    => 'integer',
								'default' => 20,
								'maximum' => 50,
							),
							'cursor'  => array(
								'type'        => 'string',
								'description' => 'Pagination cursor from a previous list_memories response.',
							),
							'context' => array( 'type' => 'string' ),
						),
						'required'   => array(),
					),
				),
				array(
					'name'        => 'tag_memory',
					'description' => 'Move a memory to a different group and/or change its priority. Use to reorganise memories as they accumulate, merge orphaned memories into groups, or promote important ones.',
					'inputSchema' => array(
						'type'       => 'object',
						'properties' => array(
							'uri'      => array(
								'type'        => 'string',
								'description' => 'URI of the memory to update (from resources/list or search_memory)',
							),
							'group'    => array(
								'type'        => 'string',
								'description' => 'New group/category. Creates the group if it does not exist.',
							),
							'priority' => array(
								'type' => 'string',
								'enum' => array( 'critical', 'important', 'normal', 'low' ),
							),
							'context'  => array(
								'type'        => 'string',
								'description' => 'Strongly recommended: why you are reorganising this.',
							),
						),
						'required'   => array( 'uri' ),
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
			'list_memories'       => $this->tool_list_memories( $args ),
			'tag_memory'          => $this->tool_tag_memory( $args ),
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

	private function tool_remember( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min). Please wait a moment and try again." );
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

		$possible_related       = null;
		$possible_contradiction = null;

		if ( ! empty( $search_results ) ) {
			$content_hash    = md5( $content );
			$content_excerpt = mb_substr( $content, 0, 200, 'UTF-8' );

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

				// Only run similarity check once — keep the highest-scoring match found.
				if ( ! $possible_contradiction && ! $possible_related ) {
					similar_text( $content_excerpt, (string) ( $result['excerpt'] ?? '' ), $pct );
					if ( $pct >= 70 ) {
						$possible_contradiction = array(
							'uri'        => $result['uri'],
							'name'       => $result['name'],
							'excerpt'    => $result['excerpt'],
							'updated_at' => $result['updated_at'] ?? '',
						);
					} elseif ( $pct >= 40 ) {
						$possible_related = array(
							'uri'     => $result['uri'],
							'name'    => $result['name'],
							'excerpt' => $result['excerpt'],
						);
					}
				}
			}
		}

		$name = $args['name'] ?? '';
		if ( ! $name ) {
			$name = mb_substr( wp_strip_all_tags( $content ), 0, 60, 'UTF-8' );
			$name = preg_replace( '/\s+/', ' ', trim( $name ) ) ?? $name;
		}

		// Validate and normalise expires_at to MySQL datetime before inserting.
		$expires_at_db = '';
		$expires_at    = sanitize_text_field( $args['expires_at'] ?? '' );
		if ( $expires_at !== '' ) {
			try {
				$dt            = new \DateTime( $expires_at, new \DateTimeZone( 'UTC' ) );
				$expires_at_db = $dt->format( 'Y-m-d H:i:s' );
			} catch ( \Throwable ) {
				return $this->tool_error( 'invalid_expires_at', 'expires_at must be a valid ISO 8601 datetime, e.g. "2026-12-31T23:59:59Z".' );
			}
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
		if ( $expires_at_db !== '' ) {
			update_post_meta( $post_id, '_pressocampus_expires_at', $expires_at_db );
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
		if ( $possible_related ) {
			$result_data['possible_related'] = $possible_related;
		}
		if ( $possible_contradiction ) {
			$result_data['possible_contradiction'] = $possible_contradiction;
		}

		return $this->tool_success( $result_data );
	}

	private function tool_forget( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min). Please wait a moment." );
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
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min)." );
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
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min)." );
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
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min)." );
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

	private function tool_list_memories( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'read' ) ) {
			$reads_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_reads'] ?? 60 );
			return $this->tool_error( 'rate_limit_exceeded', "Read rate limit reached ({$reads_per_min}/min)." );
		}

		$user_id = Auth::get_current_user_id();
		$host    = Auth::get_site_host();
		$group   = sanitize_text_field( $args['group'] ?? '' );
		$sort    = in_array( $args['sort'] ?? 'date_desc', array( 'date_desc', 'date_asc', 'name_asc' ), true )
			? $args['sort']
			: 'date_desc';
		$limit   = min( 50, max( 1, (int) ( $args['limit'] ?? 20 ) ) );
		$cursor  = isset( $args['cursor'] ) && $args['cursor'] !== '' ? (string) $args['cursor'] : null;
		$page    = $cursor ? max( 1, (int) base64_decode( $cursor, true ) ) : 1; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$context = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';
		$offset  = ( $page - 1 ) * $limit;

		$soul_uri  = Soul::get_uri( $host );
		$index_uri = Soul::get_index_uri( $host );

		$orderby = 'modified';
		$order   = 'DESC';
		if ( $sort === 'date_asc' ) {
			$order = 'ASC';
		} elseif ( $sort === 'name_asc' ) {
			$orderby = 'title';
			$order   = 'ASC';
		}

		$query_args = array(
			'post_type'      => PRESSOCAMPUS_CPT,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => $limit + 1, // Fetch one extra to check for next page.
			'offset'         => $offset,
			'orderby'        => $orderby,
			'order'          => $order,
			'no_found_rows'  => true,
			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'meta_query'     => array(
				array(
					'key'     => '_pressocampus_uri',
					'value'   => array( $soul_uri, $index_uri ),
					'compare' => 'NOT IN',
				),
			),
		);

		if ( $group !== '' ) {
			$query_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => PRESSOCAMPUS_TAXONOMY,
					'field'    => 'name',
					'terms'    => $group,
				),
			);
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		$count = 0;

		foreach ( $query->posts as $post ) {
			if ( $count >= $limit ) {
				break;
			}
			$uri         = (string) get_post_meta( $post->ID, '_pressocampus_uri', true );
			$post_groups = wp_get_object_terms( $post->ID, PRESSOCAMPUS_TAXONOMY, array( 'fields' => 'names' ) );
			$group_label = ( ! is_wp_error( $post_groups ) && ! empty( $post_groups ) ) ? $post_groups[0] : '';
			$items[]     = array(
				'uri'        => $uri,
				'name'       => $post->post_title,
				'group'      => $group_label,
				'created_at' => $post->post_date_gmt,
				'updated_at' => $post->post_modified_gmt,
				'priority'   => (string) ( get_post_meta( $post->ID, '_pressocampus_annotation_priority', true ) ?: 'normal' ),
				'confidence' => (string) ( get_post_meta( $post->ID, '_pressocampus_confidence', true ) ?: 'medium' ),
				'excerpt'    => wp_trim_words( $post->post_content, 20, '…' ),
			);
			++$count;
		}

		$result = array(
			'items' => $items,
			'count' => count( $items ),
			'page'  => $page,
		);

		if ( count( $query->posts ) > $limit ) {
			$result['next_cursor'] = base64_encode( (string) ( $page + 1 ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		$this->audit_log->record( 'list_memories', $user_id, Auth::get_current_client_name(), '', $group ?: 'all', $context );

		return $this->tool_success( $result );
	}

	private function tool_tag_memory( array $args ): array {
		if ( ! $this->auth->check_rate_limit( 'write' ) ) {
			$writes_per_min = (int) ( get_option( 'pressocampus_settings', array() )['rate_limit_writes'] ?? 30 );
			return $this->tool_error( 'rate_limit_exceeded', "Write rate limit reached ({$writes_per_min}/min)." );
		}

		$user_id  = Auth::get_current_user_id();
		$uri      = sanitize_text_field( $args['uri'] ?? '' );
		$group    = isset( $args['group'] ) ? sanitize_text_field( $args['group'] ) : null;
		$priority = isset( $args['priority'] ) && in_array( $args['priority'], array( 'critical', 'important', 'normal', 'low' ), true )
			? $args['priority']
			: null;
		$context  = substr( $args['context'] ?? '', 0, 200 ) ?: '[no context provided]';

		if ( ! $uri ) {
			return $this->tool_error( 'missing_uri', 'uri is required.' );
		}
		if ( $group === null && $priority === null ) {
			return $this->tool_error( 'nothing_to_update', 'Provide at least one of: group, priority.' );
		}

		$index_entry = $this->resource_index->get_by_uri( $uri );
		if ( ! $index_entry || (int) $index_entry['user_id'] !== $user_id ) {
			return $this->tool_error( 'not_found', 'Memory not found.', 404 );
		}

		$post_id = (int) $index_entry['post_id'];

		// Guard against accidentally tagging protected resources.
		$host = Auth::get_site_host();
		if ( Soul::is_protected( $uri, $host ) ) {
			return $this->tool_error( 'protected_resource', 'Cannot retag the soul or index.' );
		}

		if ( $group !== null ) {
			wp_set_object_terms( $post_id, $group, PRESSOCAMPUS_TAXONOMY );
		}
		if ( $priority !== null ) {
			update_post_meta( $post_id, '_pressocampus_annotation_priority', $priority );
		}

		$this->resource_index->mark_dirty( $user_id );
		$this->audit_log->record( 'tag_memory', $user_id, Auth::get_current_client_name(), $uri, (string) get_the_title( $post_id ), $context );

		$result = array( 'uri' => $uri );
		if ( $group !== null ) {
			$result['group'] = $group;
		}
		if ( $priority !== null ) {
			$result['priority'] = $priority;
		}

		return $this->tool_success( $result );
	}

	private function tool_success( array $data ): array {
		return array(
			'isError' => false,
			'content' => array(
				array(
					'type' => 'text',
					'text' => wp_json_encode( $data ),
				),
			),
		);
	}

	private function tool_error( string $code, string $message, int $status = 400 ): array {
		$result = array(
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

		if ( $status !== 400 ) {
			$result['__http_status'] = $status;
		}

		return $result;
	}

	private function rpc_error( int $code, string $message, int $http_status = 400 ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $http_status reserved for future transport-level RPC errors
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

	/**
	 * Set CORS and content-type response headers.
	 *
	 * Returns true when the request should proceed, false when the Origin
	 * header is present but not in the allowed list. Callers MUST return
	 * HTTP 403 when this method returns false (MCP spec §Transports — DNS
	 * rebinding protection).
	 */
	private function set_cors_headers(): bool {
		$origin = esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ?? '' ) );

		header( 'Content-Type: application/json; charset=utf-8' );

		if ( $origin !== '' ) {
			$settings        = get_option( 'pressocampus_settings', array() );
			$allowed_origins = array_filter(
				array_map( 'trim', explode( "\n", $settings['cors_origins'] ?? '' ) )
			);

			$site_origin    = ( wp_parse_url( home_url(), PHP_URL_SCHEME ) ?: 'https' ) . '://' . ( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'localhost' );
			$always_allowed = array( $site_origin );

			if ( in_array( $origin, $allowed_origins, true ) || in_array( $origin, $always_allowed, true ) ) {
				header( 'Access-Control-Allow-Origin: ' . $origin );
				header( 'Access-Control-Allow-Credentials: true' );
				header( 'Vary: Origin' );
			} else {
				// Origin present but not allowed — caller must respond with 403.
				return false;
			}
		}

		header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version, MCP-Session-Id' );

		return true;
	}

	public function handle_get( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST callback; $request required by register_rest_route
		if ( ! $this->set_cors_headers() ) {
			return new \WP_REST_Response( null, 403 );
		}
		// SSE not supported; signal clients to fall back to plain JSON responses.
		return new \WP_REST_Response( null, 405 );
	}

	public function handle_cors_preflight( \WP_REST_Request $request ): \WP_REST_Response { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- WP REST callback; $request required by register_rest_route
		if ( ! $this->set_cors_headers() ) {
			return new \WP_REST_Response( null, 403 );
		}
		return new \WP_REST_Response( null, 204 );
	}
}
