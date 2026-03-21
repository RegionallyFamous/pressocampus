<?php
/**
 * Settings — registers the Pressocampus admin menu and renders the
 * Settings (Connect + Advanced) and History pages.
 *
 * @package Pressocampus
 * @license GPL-2.0-or-later
 */

namespace Pressocampus;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	public function __construct(
		private Auth $auth,
		private AuditLog $audit_log,
		private Soul $soul
	) {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );

		add_action( 'wp_ajax_pressocampus_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_pressocampus_revoke_client', array( $this, 'ajax_revoke_client' ) );
		add_action( 'wp_ajax_pressocampus_export_brain', array( $this, 'ajax_export_brain' ) );
		add_action( 'wp_ajax_pressocampus_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_pressocampus_save_settings', array( $this, 'ajax_save_settings' ) );
		add_action( 'wp_ajax_pressocampus_dismiss_notice', array( $this, 'ajax_dismiss_notice' ) );
	}

	// Menu registration

	public function register_menu(): void {
        // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64 encoding an inline SVG menu icon, not obfuscating code
		$svg_icon = base64_encode(
			'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<path fill="#a0a5aa" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z'
			. 'm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z'
			. 'm-1-13h2v6h-2zm0 8h2v2h-2z"/></svg>'
		);

		add_menu_page(
			__( 'Pressocampus', 'pressocampus' ),
			__( 'Pressocampus', 'pressocampus' ),
			'manage_options',
			'pressocampus',
			array( $this, 'render_settings_page' ),
			'data:image/svg+xml;base64,' . $svg_icon,
			30
		);

		// Replace the auto-generated duplicate submenu entry.
		add_submenu_page(
			'pressocampus',
			__( 'Settings', 'pressocampus' ),
			__( 'Settings', 'pressocampus' ),
			'manage_options',
			'pressocampus',
			array( $this, 'render_settings_page' )
		);

		add_submenu_page(
			'pressocampus',
			__( 'History', 'pressocampus' ),
			__( 'History', 'pressocampus' ),
			'manage_options',
			'pressocampus-history',
			array( $this, 'render_history_page' )
		);
	}

	// Minimal inline CSS — only for elements WordPress core does not cover.

	private function shared_styles(): string {
		return <<<'CSS'
<style>
.pc-tab-panel { display: none; }
.pc-tab-panel.active { display: block; }
.pc-badge { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; font-weight: 600; background: #e5f0ff; color: #2271b1; }
.pc-badge.remember { background: #d7ffe7; color: #006600; }
.pc-badge.forget   { background: #fce8e8; color: #9b1c1c; }
.pc-badge.update_memory, .pc-badge.update_soul, .pc-badge.update_soul_section { background: #fff3d7; color: #7a4800; }
.pc-badge.resources_list, .pc-badge.resources_read, .pc-badge.search_memory { background: #f0e5ff; color: #5a0099; }
.pc-dropdown-wrap { position: relative; display: inline-block; }
.pc-dropdown-menu { display: none; position: absolute; top: 100%; left: 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; box-shadow: 0 4px 16px rgba(0,0,0,.12); z-index: 99; min-width: 220px; padding: 4px 0; }
.pc-dropdown-menu.open { display: block; }
.pc-dropdown-item { display: block; width: 100%; background: none; border: none; padding: 8px 14px; text-align: left; font-size: 13px; cursor: pointer; color: #2c3338; white-space: nowrap; }
.pc-dropdown-item:hover { background: #f0f6fb; color: #2271b1; }
#pc-toast { position: fixed; bottom: 24px; right: 24px; background: #2c3338; color: #fff; padding: 10px 18px; border-radius: 4px; font-size: 13px; z-index: 9999; display: none; box-shadow: 0 4px 16px rgba(0,0,0,.25); }
</style>
CSS;
	}

	// Settings page (Connect + Advanced tabs)

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pressocampus' ) );
		}

		$show_welcome = isset( $_GET['welcome'] ) && $_GET['welcome'] === '1'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active_tab   = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$user_id      = get_current_user_id();
		$mcp_url      = home_url( '/brain' );
		$site_name    = get_bloginfo( 'name' );
		$settings     = $this->get_settings();

		// Soul info
		$soul_post       = $this->soul->get_post( $user_id );
		$soul_status     = $this->soul->get_status( $user_id );
		$soul_word_count = 0;
		$soul_updated    = '';
		$soul_revisions  = 0;

		if ( $soul_post instanceof \WP_Post ) {
			$soul_word_count = str_word_count( wp_strip_all_tags( $soul_post->post_content ) );
			$soul_updated    = human_time_diff( strtotime( $soul_post->post_modified_gmt ), time() ) . ' ' . __( 'ago', 'pressocampus' );
			$soul_revisions  = count( wp_get_post_revisions( $soul_post->ID, array( 'fields' => 'ids' ) ) );
		}

		// Connected apps
		$clients = $this->get_oauth_clients( $user_id );

		echo $this->shared_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pressocampus', 'pressocampus' ); ?></h1>

			<?php if ( $show_welcome ) : ?>
			<div class="notice notice-success">
				<p>
					<strong><?php esc_html_e( 'Your memories are online.', 'pressocampus' ); ?></strong>
					<?php esc_html_e( 'Pressocampus is active. Copy your Brain URL below and paste it into your AI client to connect.', 'pressocampus' ); ?>
				</p>
			</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Pressocampus settings tabs', 'pressocampus' ); ?>">
				<a href="#" class="nav-tab <?php echo $active_tab === 'connect' ? 'nav-tab-active' : ''; ?>" data-tab="connect"><?php esc_html_e( 'Connect', 'pressocampus' ); ?></a>
				<a href="#" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>" data-tab="advanced"><?php esc_html_e( 'Advanced', 'pressocampus' ); ?></a>
			</nav>

			<!-- ===== CONNECT TAB ===== -->
			<div id="pc-tab-connect" class="pc-tab-panel <?php echo $active_tab === 'connect' ? 'active' : ''; ?>">

				<h2><?php esc_html_e( 'Brain Endpoint', 'pressocampus' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="pc-mcp-url"><?php esc_html_e( 'Brain URL', 'pressocampus' ); ?></label>
						</th>
						<td>
							<input id="pc-mcp-url" type="text" readonly class="regular-text code" value="<?php echo esc_attr( $mcp_url ); ?>" />
							<button class="button" onclick="pcCopy('<?php echo esc_js( $mcp_url ); ?>', this)"><?php esc_html_e( 'Copy', 'pressocampus' ); ?></button>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Share Brain', 'pressocampus' ); ?></th>
						<td>
							<div class="pc-dropdown-wrap">
								<button class="button" id="pc-share-btn" onclick="pcToggleDropdown(event)"><?php esc_html_e( 'Share Brain ▾', 'pressocampus' ); ?></button>
								<div class="pc-dropdown-menu" id="pc-share-menu">
									<button class="pc-dropdown-item" onclick="pcCopy('<?php echo esc_js( $mcp_url ); ?>', null); pcCloseDropdown();"><?php esc_html_e( 'Copy URL', 'pressocampus' ); ?></button>
									<button class="pc-dropdown-item" onclick="pcCopyClaudeConfig(); pcCloseDropdown();"><?php esc_html_e( 'Copy Claude Desktop config', 'pressocampus' ); ?></button>
									<button class="pc-dropdown-item" onclick="pcCopyCursorConfig(); pcCloseDropdown();"><?php esc_html_e( 'Copy Cursor config', 'pressocampus' ); ?></button>
									<button class="pc-dropdown-item" onclick="pcCopyGenericConfig(); pcCloseDropdown();"><?php esc_html_e( 'Copy generic MCP config', 'pressocampus' ); ?></button>
								</div>
							</div>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Soul', 'pressocampus' ); ?></h2>
				<p><?php esc_html_e( 'Your Soul is a persistent note about you that your AI reads at the start of every session — like a cover letter for your memories.', 'pressocampus' ); ?></p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'pressocampus' ); ?></th>
						<td>
							<?php if ( $soul_status === 'empty' ) : ?>
								<?php esc_html_e( 'Your soul is empty — connect your AI to set it up.', 'pressocampus' ); ?>
							<?php else : ?>
								<?php
								printf(
									/* translators: 1: word count, 2: human time diff, 3: revision count */
									esc_html__( '%1$s words · last updated %2$s · %3$s revisions', 'pressocampus' ),
									'<strong>' . esc_html( number_format_i18n( $soul_word_count ) ) . '</strong>',
									'<strong>' . esc_html( $soul_updated ) . '</strong>',
									'<strong>' . esc_html( number_format_i18n( $soul_revisions ) ) . '</strong>'
								);
								?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Starter Prompt', 'pressocampus' ); ?></th>
						<td>
							<?php
							$starter = sprintf(
								/* translators: %s: site name */
								__( "I've just connected my memory store at %s to this conversation. Please introduce yourself, explain what you can now do with my memory store, then read my soul and tell me what you learned about me before we begin.", 'pressocampus' ),
								$site_name
							);
							?>
							<button class="button" onclick="pcCopy(<?php echo wp_json_encode( $starter ); ?>, this)"><?php esc_html_e( 'Copy Starter Prompt', 'pressocampus' ); ?></button>
							<p class="description"><?php esc_html_e( 'Paste this into your AI client to kick off the first conversation.', 'pressocampus' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Connection Test', 'pressocampus' ); ?></h2>
				<p><?php esc_html_e( 'Verify your AI client can reach this site\'s MCP endpoint.', 'pressocampus' ); ?></p>
				<p>
					<button class="button button-primary" id="pc-test-btn" onclick="pcTestConnection()"><?php esc_html_e( 'Test Connection', 'pressocampus' ); ?></button>
					<span id="pc-test-result" style="margin-left:8px;vertical-align:middle"></span>
				</p>

			</div><!-- /connect tab -->

			<!-- ===== ADVANCED TAB ===== -->
			<div id="pc-tab-advanced" class="pc-tab-panel <?php echo $active_tab === 'advanced' ? 'active' : ''; ?>">

				<h2><?php esc_html_e( 'Connected Apps', 'pressocampus' ); ?></h2>
				<?php if ( empty( $clients ) ) : ?>
					<p><?php esc_html_e( 'No AI clients connected yet.', 'pressocampus' ); ?></p>
				<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'App', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connected', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Last used', 'pressocampus' ); ?></th>
							<th scope="col" style="width:90px"></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $clients as $client ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $client['name'] ); ?></strong></td>
							<td>
								<?php
								$connected = ! empty( $client['created_at'] )
									? sprintf(
										/* translators: %s: human-readable time */
										__( 'Connected %s ago', 'pressocampus' ),
										human_time_diff( strtotime( $client['created_at'] ), time() )
									)
									: '';
								echo esc_html( $connected );
								?>
							</td>
							<td>
								<?php
								$last_used = ! empty( $client['last_used'] )
									? sprintf(
										/* translators: %s: human-readable time */
										__( 'Last used %s ago', 'pressocampus' ),
										human_time_diff( strtotime( $client['last_used'] ), time() )
									)
									: __( 'Never used', 'pressocampus' );
								echo esc_html( $last_used );
								?>
							</td>
							<td>
								<button class="button button-link-delete"
									onclick="pcRevokeClient(<?php echo esc_attr( $client['id'] ); ?>, <?php echo wp_json_encode( $client['name'] ); ?>, this)"
								><?php esc_html_e( 'Revoke', 'pressocampus' ); ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php endif; ?>

				<form id="pc-settings-form" onsubmit="pcSaveSettings(event)">
					<?php wp_nonce_field( 'pressocampus_save_settings', 'pc_settings_nonce' ); ?>

					<h2><?php esc_html_e( 'CORS & Access', 'pressocampus' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="pc-cors-origins"><?php esc_html_e( 'Allowed CORS Origins', 'pressocampus' ); ?></label>
							</th>
							<td>
								<textarea id="pc-cors-origins" name="cors_origins" class="large-text code" rows="4"><?php echo esc_textarea( $settings['cors_origins'] ?? '' ); ?></textarea>
								<p class="description"><?php esc_html_e( 'One origin per line. Leave blank only if your AI client does not send an Origin header — allowing all origins is permissive. Example: https://claude.ai', 'pressocampus' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Rate Limits', 'pressocampus' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="pc-rate-reads"><?php esc_html_e( 'Reads per minute', 'pressocampus' ); ?></label>
							</th>
							<td>
								<input id="pc-rate-reads" name="rate_limit_reads" type="number" min="1" max="1000" class="small-text" value="<?php echo esc_attr( $settings['rate_limit_reads'] ?? 60 ); ?>" />
								<p class="description"><?php esc_html_e( 'Requests beyond this limit receive a 429 error and your AI will pause briefly before retrying.', 'pressocampus' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pc-rate-writes"><?php esc_html_e( 'Writes per minute', 'pressocampus' ); ?></label>
							</th>
							<td>
								<input id="pc-rate-writes" name="rate_limit_writes" type="number" min="1" max="1000" class="small-text" value="<?php echo esc_attr( $settings['rate_limit_writes'] ?? 30 ); ?>" />
								<p class="description"><?php esc_html_e( 'Requests beyond this limit receive a 429 error and your AI will pause briefly before retrying.', 'pressocampus' ); ?></p>
							</td>
						</tr>
					</table>

					<h2><?php esc_html_e( 'Limits', 'pressocampus' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">
								<label for="pc-max-content"><?php esc_html_e( 'Max content size (KB)', 'pressocampus' ); ?></label>
							</th>
							<td>
								<input id="pc-max-content" name="max_content_size" type="number" min="1" max="10240" class="small-text" value="<?php echo esc_attr( ( $settings['max_content_size'] ?? 524288 ) / 1024 ); ?>" />
								<p class="description"><?php esc_html_e( 'Per memory.', 'pressocampus' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pc-memory-limit"><?php esc_html_e( 'Max memories per user', 'pressocampus' ); ?></label>
							</th>
							<td>
								<input id="pc-memory-limit" name="memory_count_limit" type="number" min="1" max="100000" class="small-text" value="<?php echo esc_attr( $settings['memory_count_limit'] ?? 1000 ); ?>" />
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="pc-audit-retention"><?php esc_html_e( 'Audit log retention (days)', 'pressocampus' ); ?></label>
							</th>
							<td>
								<input id="pc-audit-retention" name="audit_log_retention_days" type="number" min="1" max="3650" class="small-text" value="<?php echo esc_attr( $settings['audit_log_retention_days'] ?? 90 ); ?>" />
								<p class="description"><?php esc_html_e( 'History entries older than this are deleted weekly.', 'pressocampus' ); ?></p>
							</td>
						</tr>
					</table>

					<p class="submit">
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Save Settings', 'pressocampus' ); ?></button>
						<span id="pc-save-result" style="margin-left:8px;vertical-align:middle"></span>
					</p>
				</form>

				<?php if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) : ?>
				<div class="notice notice-warning inline">
					<p>
						<strong><?php esc_html_e( 'Server Cron Required', 'pressocampus' ); ?></strong><br />
						<?php esc_html_e( 'DISABLE_WP_CRON is enabled. Pressocampus scheduled tasks (token expiry checks, log purging) will not run automatically. Add this to your server cron:', 'pressocampus' ); ?>
					</p>
					<pre style="background:#f6f7f7;padding:8px 12px;margin:0 0 12px;font-size:12px;overflow-x:auto">* * * * * curl -s <?php echo esc_url( site_url( '/wp-cron.php?doing_wp_cron' ) ); ?> &gt; /dev/null 2&gt;&amp;1</pre>
				</div>
				<?php endif; ?>

				<h2><?php esc_html_e( 'Data', 'pressocampus' ); ?></h2>
				<p>
					<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=pressocampus_export_brain&_wpnonce=' . wp_create_nonce( 'pressocampus_export_brain' ) ) ); ?>"
						class="button" download
						title="<?php esc_attr_e( 'Export all memories as JSON or ZIP', 'pressocampus' ); ?>">
						<?php esc_html_e( 'Download Brain', 'pressocampus' ); ?>
					</a>
					<span class="description" style="margin-left:8px"><?php esc_html_e( 'Exports all memories as JSON.', 'pressocampus' ); ?></span>
				</p>
				<p class="description">
					<?php esc_html_e( 'To fully uninstall Pressocampus, deactivate and delete this plugin from the Plugins page.', 'pressocampus' ); ?>
				</p>

			</div><!-- /advanced tab -->

		</div><!-- /wrap -->

		<div id="pc-toast"></div>

		<?php $this->render_settings_js( $mcp_url ); ?>
		<?php
	}

	// History page

	public function render_history_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'pressocampus' ) );
		}

		$user_id  = get_current_user_id();
		$per_page = 50;
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page          = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
		$search        = sanitize_text_field( $_GET['pc_search'] ?? '' );
		$agent_filter  = sanitize_text_field( $_GET['pc_agent'] ?? '' );
		$action_filter = sanitize_text_field( $_GET['pc_action'] ?? '' );
        // phpcs:enable

		$result      = $this->audit_log->get_entries( $user_id, $search, $agent_filter, $action_filter, $page, $per_page );
		$items       = $result['items'];
		$total       = $result['total'];
		$total_pages = (int) ceil( $total / $per_page );
		$agent_names = $this->audit_log->get_agent_names( $user_id );

		$all_actions = array( 'remember', 'forget', 'update_memory', 'update_soul', 'resources_list', 'resources_read' );

		$base_url = admin_url( 'admin.php?page=pressocampus-history' );

		echo $this->shared_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pressocampus — History', 'pressocampus' ); ?></h1>

			<form method="get" action="<?php echo esc_url( $base_url ); ?>">
				<input type="hidden" name="page" value="pressocampus-history" />
				<div class="tablenav top">
					<div class="alignleft actions">
						<input type="text" name="pc_search" class="regular-text" placeholder="<?php esc_attr_e( 'Search memories…', 'pressocampus' ); ?>" value="<?php echo esc_attr( $search ); ?>" />

						<select name="pc_agent">
							<option value=""><?php esc_html_e( 'All agents', 'pressocampus' ); ?></option>
							<?php foreach ( $agent_names as $name ) : ?>
								<option value="<?php echo esc_attr( $name ); ?>" <?php selected( $agent_filter, $name ); ?>><?php echo esc_html( $name ); ?></option>
							<?php endforeach; ?>
						</select>

						<select name="pc_action">
							<option value=""><?php esc_html_e( 'All actions', 'pressocampus' ); ?></option>
							<?php foreach ( $all_actions as $act ) : ?>
								<option value="<?php echo esc_attr( $act ); ?>" <?php selected( $action_filter, $act ); ?>><?php echo esc_html( $act ); ?></option>
							<?php endforeach; ?>
						</select>

						<input type="submit" class="button" value="<?php esc_attr_e( 'Filter', 'pressocampus' ); ?>" />

						<?php if ( $search || $agent_filter || $action_filter ) : ?>
							<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Clear', 'pressocampus' ); ?></a>
						<?php endif; ?>
					</div>
					<div class="alignright actions">
						<a href="<?php echo esc_url( admin_url( 'admin-ajax.php?action=pressocampus_export_csv&_wpnonce=' . wp_create_nonce( 'pressocampus_export_csv' ) ) ); ?>"
							class="button" download>
							<?php esc_html_e( 'Export CSV', 'pressocampus' ); ?>
						</a>
					</div>
					<br class="clear" />
				</div>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %s: total count */
					esc_html__( '%s entries', 'pressocampus' ),
					esc_html( number_format_i18n( $total ) )
				);
				?>
			</p>

			<?php if ( empty( $items ) ) : ?>
				<div class="notice notice-info inline"><p><?php esc_html_e( 'No history entries found.', 'pressocampus' ); ?></p></div>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Agent', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Memory', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Context', 'pressocampus' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Date', 'pressocampus' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						$action_labels = array(
							'remember'                => __( 'Saved memory', 'pressocampus' ),
							'forget'                  => __( 'Deleted memory', 'pressocampus' ),
							'update_memory'           => __( 'Updated memory', 'pressocampus' ),
							'update_soul'             => __( 'Updated soul', 'pressocampus' ),
							'update_soul_section'     => __( 'Updated soul section', 'pressocampus' ),
							'resources_list'          => __( 'Listed memories', 'pressocampus' ),
							'resources_read'          => __( 'Read memory', 'pressocampus' ),
							'oauth_client_registered' => __( 'App registered', 'pressocampus' ),
							'oauth_authorized'        => __( 'App connected', 'pressocampus' ),
							'oauth_token_issued'      => __( 'Token issued', 'pressocampus' ),
						);
						?>
						<?php foreach ( $items as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row['oauth_client_name'] ); ?></td>
								<td>
									<span class="pc-badge <?php echo esc_attr( $row['action'] ); ?>"
										title="<?php echo esc_attr( $row['action'] ); ?>">
										<?php echo esc_html( $action_labels[ $row['action'] ] ?? $row['action'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( ! empty( $row['memory_name'] ) ) : ?>
										<span title="<?php echo esc_attr( $row['memory_uri'] ); ?>"><?php echo esc_html( $row['memory_name'] ); ?></span>
									<?php elseif ( ! empty( $row['memory_uri'] ) ) : ?>
										<code style="font-size:11px"><?php echo esc_html( $row['memory_uri'] ); ?></code>
									<?php else : ?>
										<span style="color:#ccc">—</span>
									<?php endif; ?>
								</td>
								<td style="max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?php echo esc_attr( $row['context'] ); ?>">
									<?php echo esc_html( $row['context'] ?: '—' ); ?>
								</td>
								<td style="white-space:nowrap" title="<?php echo esc_attr( $row['created_at'] ); ?>">
									<?php
									$ts = strtotime( $row['created_at'] );
									echo esc_html( $ts ? human_time_diff( $ts, time() ) . ' ' . __( 'ago', 'pressocampus' ) : $row['created_at'] );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php if ( $total_pages > 1 ) : ?>
					<?php
					$prev_url = esc_url(
						add_query_arg(
							array(
								'paged'     => $page - 1,
								'pc_search' => $search,
								'pc_agent'  => $agent_filter,
								'pc_action' => $action_filter,
							),
							$base_url
						)
					);
					$next_url = esc_url(
						add_query_arg(
							array(
								'paged'     => $page + 1,
								'pc_search' => $search,
								'pc_agent'  => $agent_filter,
								'pc_action' => $action_filter,
							),
							$base_url
						)
					);
					?>
				<div class="tablenav bottom">
					<div class="tablenav-pages">
						<span class="pagination-links">
							<?php if ( $page > 1 ) : ?>
								<a class="button" href="<?php echo $prev_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_url() above ?>">← <?php esc_html_e( 'Prev', 'pressocampus' ); ?></a>
							<?php endif; ?>
							<span class="paging-input">
								<?php
								printf(
									/* translators: 1: current page, 2: total pages */
									esc_html__( 'Page %1$d of %2$d', 'pressocampus' ),
									(int) $page,
									(int) $total_pages
								);
								?>
							</span>
							<?php if ( $page < $total_pages ) : ?>
								<a class="button" href="<?php echo $next_url; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped via esc_url() above ?>"><?php esc_html_e( 'Next', 'pressocampus' ); ?> →</a>
							<?php endif; ?>
						</span>
					</div>
					<br class="clear" />
				</div>
				<?php endif; ?>

			<?php endif; ?>
		</div>
		<?php
	}

	// Inline JS for settings page

	private function render_settings_js( string $mcp_url ): void {
		$claude_config = wp_json_encode(
			array(
				'mcpServers' => array(
					'pressocampus' => array(
						'command' => 'npx',
						'args'    => array( '-y', 'mcp-remote', $mcp_url ),
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$cursor_config = wp_json_encode(
			array(
				'mcpServers' => array(
					'pressocampus' => array(
						'url'  => $mcp_url,
						'type' => 'http',
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$generic_config = wp_json_encode(
			array(
				'mcp' => array(
					array(
						'name'     => 'pressocampus',
						'endpoint' => $mcp_url,
						'auth'     => 'oauth2',
					),
				),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		);

		$ajax_url = admin_url( 'admin-ajax.php' );
		$nonce    = wp_create_nonce( 'pressocampus_admin' );

		?>
		<script>
		(function(){
			// Tab switching
			document.querySelectorAll('.nav-tab[data-tab]').forEach(function(btn){
				btn.addEventListener('click', function(e){
					e.preventDefault();
					var tab = this.dataset.tab;
					document.querySelectorAll('.nav-tab[data-tab]').forEach(function(b){ b.classList.remove('nav-tab-active'); });
					document.querySelectorAll('.pc-tab-panel').forEach(function(p){ p.classList.remove('active'); });
					this.classList.add('nav-tab-active');
					var panel = document.getElementById('pc-tab-' + tab);
					if (panel) panel.classList.add('active');
				});
			});

			// Toast
			var toastTimer;
			window.pcToast = function(msg, duration){
				var el = document.getElementById('pc-toast');
				if (!el) return;
				el.textContent = msg;
				el.style.display = 'block';
				clearTimeout(toastTimer);
				toastTimer = setTimeout(function(){ el.style.display = 'none'; }, duration || 2500);
			};

			// Copy to clipboard
			window.pcCopy = function(text, btn){
				navigator.clipboard.writeText(text).then(function(){
					pcToast('Copied!');
					if (btn) {
						var orig = btn.textContent;
						btn.textContent = '✓ Copied';
						setTimeout(function(){ btn.textContent = orig; }, 1800);
					}
				}).catch(function(){
					pcToast('Copy failed — please copy manually.');
				});
			};

			// Share Brain dropdown
			window.pcToggleDropdown = function(e){
				e.stopPropagation();
				var menu = document.getElementById('pc-share-menu');
				if (menu) menu.classList.toggle('open');
			};
			window.pcCloseDropdown = function(){
				var menu = document.getElementById('pc-share-menu');
				if (menu) menu.classList.remove('open');
			};
			document.addEventListener('click', pcCloseDropdown);

			// Share config snippets
			window.pcCopyClaudeConfig = function(){
				pcCopy(<?php echo wp_json_encode( $claude_config ); ?>, null);
			};
			window.pcCopyCursorConfig = function(){
				pcCopy(<?php echo wp_json_encode( $cursor_config ); ?>, null);
			};
			window.pcCopyGenericConfig = function(){
				pcCopy(<?php echo wp_json_encode( $generic_config ); ?>, null);
			};

			// Test connection
			window.pcTestConnection = function(){
				var btn = document.getElementById('pc-test-btn');
				var result = document.getElementById('pc-test-result');
				btn.disabled = true;
				btn.textContent = '<?php echo esc_js( __( 'Testing…', 'pressocampus' ) ); ?>';
				result.textContent = '';
				result.style.color = '';

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: 'action=pressocampus_test_connection&_wpnonce=' + <?php echo wp_json_encode( $nonce ); ?>
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Test Connection', 'pressocampus' ) ); ?>';
					if (data.success) {
						result.textContent = '✓ ' + data.data.message;
						result.style.color = '#00a32a';
					} else {
						result.textContent = '✗ ' + (data.data ? data.data.message : '<?php echo esc_js( __( 'Unknown error', 'pressocampus' ) ); ?>');
						result.style.color = '#d63638';
					}
				})
				.catch(function(err){
					btn.disabled = false;
					btn.textContent = '<?php echo esc_js( __( 'Test Connection', 'pressocampus' ) ); ?>';
					result.textContent = '✗ ' + err.message;
					result.style.color = '#d63638';
				});
			};

			// Revoke client
			window.pcRevokeClient = function(id, name, btn){
				if (!confirm('<?php echo esc_js( __( 'Revoke access for', 'pressocampus' ) ); ?> "' + name + '"?')) return;
				btn.disabled = true;

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					headers: {'Content-Type': 'application/x-www-form-urlencoded'},
					body: 'action=pressocampus_revoke_client&client_id=' + id + '&_wpnonce=' + <?php echo wp_json_encode( $nonce ); ?>
				})
				.then(function(r){ return r.json(); })
				.then(function(data){
					if (data.success) {
						var row = btn.closest('tr');
						if (row) row.remove();
						pcToast('<?php echo esc_js( __( 'Client revoked.', 'pressocampus' ) ); ?>');
					} else {
						btn.disabled = false;
						pcToast('<?php echo esc_js( __( 'Revoke failed.', 'pressocampus' ) ); ?>');
					}
				})
				.catch(function(){
					btn.disabled = false;
					pcToast('<?php echo esc_js( __( 'Revoke failed.', 'pressocampus' ) ); ?>');
				});
			};

			// Save settings
			window.pcSaveSettings = function(e){
				e.preventDefault();
				var form   = document.getElementById('pc-settings-form');
				var result = document.getElementById('pc-save-result');
				var data   = new FormData(form);
				data.append('action', 'pressocampus_save_settings');

				result.textContent = '<?php echo esc_js( __( 'Saving…', 'pressocampus' ) ); ?>';
				result.style.color = '#888';

				fetch(<?php echo wp_json_encode( $ajax_url ); ?>, {
					method: 'POST',
					body: new URLSearchParams(data)
				})
				.then(function(r){ return r.json(); })
				.then(function(resp){
					if (resp.success) {
						result.textContent = '✓ <?php echo esc_js( __( 'Saved', 'pressocampus' ) ); ?>';
						result.style.color = '#00a32a';
					} else {
						result.textContent = '✗ <?php echo esc_js( __( 'Save failed', 'pressocampus' ) ); ?>';
						result.style.color = '#d63638';
					}
					setTimeout(function(){ result.textContent = ''; }, 3000);
				})
				.catch(function(){
					result.textContent = '✗ <?php echo esc_js( __( 'Save failed', 'pressocampus' ) ); ?>';
					result.style.color = '#d63638';
				});
			};
		})();
		</script>
		<?php
	}

	// AJAX: Test connection

	public function ajax_test_connection(): void {
		check_ajax_referer( 'pressocampus_admin', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pressocampus' ) ) );
		}

		$endpoint = home_url( '/brain' );

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'   => 10,
				'sslverify' => false,
				'headers'   => array(
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				),
				'body'      => wp_json_encode(
					array(
						'jsonrpc' => '2.0',
						'id'      => 1,
						'method'  => 'ping',
						'params'  => array(),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'message' => $response->get_error_message() ) );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$json = json_decode( $body, true );

		// MCP ping returns {"jsonrpc":"2.0","id":1,"result":{}}
		// We also accept a 401/auth challenge — that means the endpoint IS reachable.
		if ( $code === 401 || ( is_array( $json ) && array_key_exists( 'result', $json ) ) ) {
			wp_send_json_success( array( 'message' => __( 'Endpoint is reachable and responding correctly.', 'pressocampus' ) ) );
		}

		$detail = is_array( $json ) && isset( $json['error']['message'] )
			? $json['error']['message']
			/* translators: %d: HTTP status code */
			: sprintf( __( 'HTTP %d', 'pressocampus' ), $code );

		/* translators: %s: error detail string */
		wp_send_json_error( array( 'message' => sprintf( __( 'Unexpected response: %s', 'pressocampus' ), $detail ) ) );
	}

	// AJAX: Revoke OAuth client

	public function ajax_revoke_client(): void {
		check_ajax_referer( 'pressocampus_admin', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pressocampus' ) ) );
		}

		// client_id is a varchar primary key (e.g. "prc_6654abc…"), not an integer.
		$client_id = sanitize_text_field( wp_unslash( $_POST['client_id'] ?? '' ) );

		if ( $client_id === '' ) {
			wp_send_json_error( array( 'message' => __( 'Invalid client ID.', 'pressocampus' ) ) );
		}

		global $wpdb;

		$clients_table = $wpdb->prefix . 'pressocampus_oauth_clients';
		$tokens_table  = $wpdb->prefix . 'pressocampus_oauth_tokens';

		$current_user_id = get_current_user_id();

		// Delete all tokens for this client first, then the client record.
		// user_id constraint prevents an admin revoking another user's client on multi-user sites.
		$wpdb->delete(
			$tokens_table,
			array(
				'client_id' => $client_id,
				'user_id'   => $current_user_id,
			),
			array( '%s', '%d' )
		);

		$deleted = $wpdb->delete(
			$clients_table,
			array(
				'id'      => $client_id,
				'user_id' => $current_user_id,
			),
			array( '%s', '%d' )
		);

		if ( $deleted === false ) {
			wp_send_json_error( array( 'message' => __( 'Database error while revoking client.', 'pressocampus' ) ) );
		}

		wp_send_json_success( array( 'message' => __( 'Client revoked.', 'pressocampus' ) ) );
	}

	// AJAX: Export brain (JSON / ZIP)

	public function ajax_export_brain(): void {
		if ( ! check_ajax_referer( 'pressocampus_export_brain', '_wpnonce', false ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'pressocampus' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'pressocampus' ) );
		}

		$user_id = get_current_user_id();

		// Fetch soul
		$soul_post = $this->soul->get_post( $user_id );

		// Fetch all memories
		$query = new \WP_Query(
			array(
				'post_type'      => PRESSOCAMPUS_CPT,
				'post_status'    => 'publish',
				'author'         => $user_id,
				'posts_per_page' => -1,
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$memories = array();

		// Soul first
		if ( $soul_post instanceof \WP_Post ) {
			$memories[] = array(
				'is_soul'     => true,
				'id'          => $soul_post->ID,
				'title'       => $soul_post->post_title,
				'content'     => $soul_post->post_content,
				'modified_at' => $soul_post->post_modified_gmt,
				'uri'         => $this->soul->get_uri( $this->auth->get_site_host() ),
			);
		}

		foreach ( $query->posts as $post ) {
			if ( $soul_post instanceof \WP_Post && $post->ID === $soul_post->ID ) {
				continue;
			}
			$memories[] = array(
				'is_soul'     => false,
				'id'          => $post->ID,
				'title'       => $post->post_title,
				'content'     => $post->post_content,
				'created_at'  => $post->post_date_gmt,
				'modified_at' => $post->post_modified_gmt,
				'uri'         => rest_url( 'pressocampus/v1/resources/' . $post->ID ),
			);
		}

		$export = array(
			'exported_at' => gmdate( 'c' ),
			'site'        => get_bloginfo( 'url' ),
			'version'     => PRESSOCAMPUS_VERSION,
			'memories'    => $memories,
		);

		$json_string = (string) wp_json_encode( $export, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		$site_slug   = sanitize_file_name( get_bloginfo( 'name' ) );
		$date_str    = gmdate( 'Y-m-d' );

		if ( class_exists( 'ZipArchive' ) ) {
			$zip_file = tempnam( sys_get_temp_dir(), 'pc_export_' );
			$zip      = new \ZipArchive();

			if ( $zip->open( $zip_file, \ZipArchive::OVERWRITE ) === true ) {
				$zip->addFromString( 'pressocampus-brain.json', $json_string );
				$zip->close();

				header( 'Content-Type: application/zip' );
				header( 'Content-Disposition: attachment; filename="pressocampus-' . $site_slug . '-' . $date_str . '.zip"' );
				header( 'Content-Length: ' . filesize( $zip_file ) );
				header( 'Pragma: no-cache' );
				header( 'Cache-Control: no-cache, must-revalidate' );

				readfile( $zip_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				wp_delete_file( $zip_file );
				exit;
			}
		}

		// Fallback: serve JSON directly
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pressocampus-' . $site_slug . '-' . $date_str . '.json"' );
		header( 'Content-Length: ' . strlen( $json_string ) );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-cache, must-revalidate' );

		echo $json_string; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// AJAX: Export history CSV

	public function ajax_export_csv(): void {
		if ( ! check_ajax_referer( 'pressocampus_export_csv', '_wpnonce', false ) ) {
			wp_die( esc_html__( 'Invalid nonce.', 'pressocampus' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'pressocampus' ) );
		}

		$user_id = get_current_user_id();
		$csv     = $this->audit_log->export_csv( $user_id, 30 );
		$date    = gmdate( 'Y-m-d' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="pressocampus-history-' . $date . '.csv"' );
		header( 'Pragma: no-cache' );
		header( 'Cache-Control: no-cache, must-revalidate' );

		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	// AJAX: Save settings

	public function ajax_save_settings(): void {
		check_ajax_referer( 'pressocampus_save_settings', 'pc_settings_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pressocampus' ) ) );
		}

		$cors_raw       = sanitize_textarea_field( wp_unslash( $_POST['cors_origins'] ?? '' ) );
		$cors_origins   = implode( "\n", array_filter( array_map( 'trim', explode( "\n", $cors_raw ) ) ) );
		$rate_reads     = max( 1, min( 1000, (int) ( $_POST['rate_limit_reads'] ?? 60 ) ) );
		$rate_writes    = max( 1, min( 1000, (int) ( $_POST['rate_limit_writes'] ?? 30 ) ) );
		$max_kb         = max( 1, min( 10240, (int) ( $_POST['max_content_size'] ?? 512 ) ) );
		$memory_limit   = max( 1, min( 100000, (int) ( $_POST['memory_count_limit'] ?? 1000 ) ) );
		$audit_log_days = max( 1, min( 3650, (int) ( $_POST['audit_log_retention_days'] ?? 90 ) ) );

		$settings = array(
			'cors_origins'             => $cors_origins,
			'rate_limit_reads'         => $rate_reads,
			'rate_limit_writes'        => $rate_writes,
			'max_content_size'         => $max_kb * 1024,  // stored in bytes
			'memory_count_limit'       => $memory_limit,
			'audit_log_retention_days' => $audit_log_days,
		);

		update_option( 'pressocampus_settings', $settings, false );

		wp_send_json_success( array( 'message' => __( 'Settings saved.', 'pressocampus' ) ) );
	}

	// AJAX: Dismiss notice

	public function ajax_dismiss_notice(): void {
		check_ajax_referer( 'pressocampus_admin', '_wpnonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pressocampus' ) ) );
		}

		$option_name = sanitize_key( wp_unslash( $_POST['notice_key'] ?? '' ) );

		if ( ! str_starts_with( $option_name, 'pressocampus_expiry_notice_' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid notice key.', 'pressocampus' ) ) );
		}

		delete_option( $option_name );
		wp_send_json_success();
	}

	// Helpers

	/**
	 * Load persisted settings with defaults.
	 *
	 * @return array<string,mixed>
	 */
	private function get_settings(): array {
		$defaults = array(
			'cors_origins'             => '',
			'rate_limit_reads'         => 60,
			'rate_limit_writes'        => 30,
			'max_content_size'         => 524288,  // 512 KB in bytes
			'memory_count_limit'       => 1000,
			'audit_log_retention_days' => 90,
		);

		$saved = get_option( 'pressocampus_settings', array() );

		return is_array( $saved ) ? array_merge( $defaults, $saved ) : $defaults;
	}

	/**
	 * Return OAuth clients visible to the given user.
	 * Queries pressocampus_oauth_clients; falls back gracefully if table absent.
	 *
	 * @param int $user_id WordPress user ID
	 * @return list<array<string,mixed>>
	 */
	private function get_oauth_clients( int $user_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . 'pressocampus_oauth_clients';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, name, created_at, last_used FROM {$table} WHERE user_id = %d ORDER BY created_at DESC", $user_id ),
			ARRAY_A
		);

		return $rows ?: array();
	}
}
