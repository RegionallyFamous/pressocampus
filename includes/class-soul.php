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

if (!defined('ABSPATH')) exit;

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

    public static function get_uri(string $host): string {
        return 'pressocampus://' . $host . '/soul';
    }

    public static function get_index_uri(string $host): string {
        return 'pressocampus://' . $host . '/index';
    }

    /**
     * Returns true for the two permanently protected URIs (soul + index).
     */
    public static function is_protected(string $uri, string $host): bool {
        return $uri === self::get_uri($host) || $uri === self::get_index_uri($host);
    }

    // -----------------------------------------------------------------------
    // Read operations
    // -----------------------------------------------------------------------

    /**
     * Retrieve the soul WP_Post for a user, or null if not yet created.
     *
     * Uses the resource_index UNIQUE KEY on `uri` for a fast single-row lookup
     * instead of a postmeta JOIN via WP_Query.
     */
    public function get_post(int $user_id): ?\WP_Post {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'localhost';
        $row  = $this->resource_index->get_by_uri(self::get_uri($host));

        if ($row === null || (int) $row['user_id'] !== $user_id) {
            return null;
        }

        $post = get_post((int) $row['post_id']);
        return ($post instanceof \WP_Post && $post->post_status === 'publish') ? $post : null;
    }

    /**
     * Return the index WP_Post for a user, or null.
     *
     * Uses the resource_index UNIQUE KEY on `uri` for a fast single-row lookup.
     */
    public function get_index_post(int $user_id): ?\WP_Post {
        $host = wp_parse_url(home_url(), PHP_URL_HOST) ?? 'localhost';
        $row  = $this->resource_index->get_by_uri(self::get_index_uri($host));

        if ($row === null || (int) $row['user_id'] !== $user_id) {
            return null;
        }

        $post = get_post((int) $row['post_id']);
        return ($post instanceof \WP_Post) ? $post : null;
    }

    // -----------------------------------------------------------------------
    // Status helpers
    // -----------------------------------------------------------------------

    /**
     * Returns 'empty' if the soul has never been written, 'complete' otherwise.
     */
    public function get_status(int $user_id): string {
        $post = $this->get_post($user_id);
        if ($post === null) {
            return 'empty';
        }

        $content = CPT::get_raw_content($post->ID);
        return str_contains($content, 'Status: empty') ? 'empty' : 'complete';
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
    public function get_snapshot(int $user_id): array {
        $host = parse_url(home_url(), PHP_URL_HOST) ?? 'localhost';
        $post = $this->get_post($user_id);

        if ($post === null) {
            $post = $this->create($user_id, $host);
        }

        $content = CPT::get_raw_content($post->ID);
        $etag    = $this->resource_index->get_content_hash($post->ID);
        if ($etag === '') {
            $etag = md5($content);
        }

        $status = str_contains($content, 'Status: empty') ? 'empty' : 'complete';

        if (mb_strlen($content, 'UTF-8') <= 2048) {
            return [
                'snapshot'  => $content,
                'etag'      => $etag,
                'truncated' => false,
                'status'    => $status,
            ];
        }

        $truncated_snapshot = mb_substr($content, 0, 500, 'UTF-8')
            . "\n\n[Soul truncated — use resources/read to get full content]";

        return [
            'snapshot'  => $truncated_snapshot,
            'etag'      => $etag,
            'truncated' => true,
            'status'    => $status,
        ];
    }

    // -----------------------------------------------------------------------
    // Write operations
    // -----------------------------------------------------------------------

    /**
     * Create the soul post for a user using the starter template.
     *
     * A transient-based mutex prevents duplicate posts under concurrent
     * initialize requests (e.g. two AI clients connecting simultaneously).
     */
    public function create(int $user_id, string $host): \WP_Post {
        $lock_key = 'pressocampus_creating_soul_' . $user_id;
        $attempts = 0;

        // Wait up to 3 s for any concurrent create to finish.
        while (get_transient($lock_key) && $attempts < 6) {
            usleep(500000);
            $attempts++;
        }

        // Another process may have created the soul while we were waiting.
        $existing = $this->get_post($user_id);
        if ($existing !== null) {
            return $existing;
        }

        set_transient($lock_key, 1, 15); // 15 s max lock

        $uri     = self::get_uri($host);
        $content = self::get_starter_template();

        $post_id = wp_insert_post([
            'post_type'    => PRESSOCAMPUS_CPT,
            'post_status'  => 'publish',
            'post_author'  => $user_id,
            'post_title'   => __('My Soul', 'pressocampus'),
            'post_content' => $content,
        ], true);

        if (is_wp_error($post_id)) {
            delete_transient($lock_key);
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
            throw new \RuntimeException($post_id->get_error_message());
        }

        add_post_meta($post_id, '_pressocampus_uri',                  $uri,            true);
        add_post_meta($post_id, '_pressocampus_mime_type',             'text/markdown', true);
        add_post_meta($post_id, '_pressocampus_schema_version',        '1.0',           true);
        add_post_meta($post_id, '_pressocampus_annotation_priority',   'critical',      true);
        add_post_meta($post_id, '_pressocampus_confidence',            'high',          true);

        $this->resource_index->upsert($post_id, $uri, $user_id, $content);

        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            delete_transient($lock_key);
            throw new \RuntimeException('Failed to retrieve newly created soul post.');
        }

        delete_transient($lock_key);
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
    public function update(int $user_id, string $content, string $host, ?string $etag = null): array {
        $uri  = self::get_uri($host);
        $post = $this->get_post($user_id);

        if ($post === null) {
            $post = $this->create($user_id, $host);
        }

        // ETag conflict check.
        if ($etag !== null) {
            $current_hash = $this->resource_index->get_content_hash($post->ID);
            if ($current_hash === '') {
                $current_hash = md5(CPT::get_raw_content($post->ID));
            }
            if (!hash_equals($current_hash, $etag)) {
                return [
                    'error'   => true,
                    'code'    => 'conflict',
                    'message' => __('Soul has been modified since you last read it. Fetch the latest version and retry.', 'pressocampus'),
                ];
            }
        }

        // Strip the "Status: empty" line once the user has populated their soul.
        $content = preg_replace(
            '/^\*\*Status: empty[^\n]*\*\*\n*/m',
            '',
            $content
        ) ?? $content;
        $content = ltrim($content);

        $result = wp_update_post([
            'ID'           => $post->ID,
            'post_content' => $content,
        ], true);

        if (is_wp_error($result)) {
            return [
                'error'   => true,
                'code'    => 'update_failed',
                'message' => $result->get_error_message(),
            ];
        }

        $this->resource_index->upsert($post->ID, $uri, $user_id, $content);

        // Notify the admin — deferred via cron so the MCP response isn't blocked
        // by SMTP latency. A per-user 30-minute cooldown prevents email floods
        // when update_soul_section is called multiple times in a session.
        $cooldown_key = 'pressocampus_soul_email_cooldown_' . $user_id;
        if (!get_transient($cooldown_key)) {
            set_transient($cooldown_key, 1, 30 * MINUTE_IN_SECONDS);
            wp_schedule_single_event(time() + 5, 'pressocampus_send_soul_notice', [$user_id]);
        }

        $new_hash = md5($content);
        return [
            'uri'  => $uri,
            'etag' => $new_hash,
        ];
    }

    /**
     * Replace a single `## Section` within the soul without touching the rest.
     *
     * If the section is not found it is appended at the end.
     *
     * Returns: ['uri' => string, 'etag' => string, 'content' => string]
     *       or ['error' => true, 'code' => string, 'message' => string]
     */
    public function update_section(int $user_id, string $section_name, string $section_content, string $host): array {
        $post = $this->get_post($user_id);
        if ($post === null) {
            $post = $this->create($user_id, $host);
        }

        $current_content = CPT::get_raw_content($post->ID);

        // Capture the ETag before modifying so update() can detect if another
        // concurrent write landed between our read and our write (lost-update prevention).
        $current_etag = $this->resource_index->get_content_hash($post->ID);
        if ($current_etag === '') {
            $current_etag = md5($current_content);
        }

        // Build the replacement block.
        $new_block = '## ' . $section_name . "\n" . rtrim($section_content);

        // Match '## {section_name}' up to (but not including) the next '##' or end-of-string.
        $pattern = '/^## ' . preg_quote($section_name, '/') . '.*?(?=^## |\z)/ms';

        if (preg_match($pattern, $current_content)) {
            $new_content = preg_replace($pattern, $new_block, $current_content, 1) ?? $current_content;
        } else {
            // Section not found — append it.
            $new_content = rtrim($current_content) . "\n\n" . $new_block;
        }

        $result = $this->update($user_id, $new_content, $host, $current_etag);

        if (!empty($result['error'])) {
            return $result;
        }

        return array_merge($result, ['content' => $new_content]);
    }

    // -----------------------------------------------------------------------
    // Deferred notifications
    // -----------------------------------------------------------------------

    /**
     * Send an admin email notifying that the soul was updated.
     *
     * Called via the pressocampus_send_soul_notice WP-Cron event so the MCP
     * response is not blocked by SMTP latency. A per-user cooldown set in
     * update() ensures at most one email per user per 30 minutes.
     *
     * @param int $user_id WordPress user whose soul was updated.
     */
    public function send_update_notice(int $user_id): void {
        $admin_email = get_option('admin_email');
        $user        = get_userdata($user_id);
        $display     = ($user instanceof \WP_User) ? $user->display_name : (string) $user_id;

        $sent = wp_mail(
            $admin_email,
            __('Soul updated on Pressocampus', 'pressocampus'),
            sprintf(
                /* translators: %s: user display name */
                __("The soul memory for %s was just updated via an AI client.\n\nLog in to WordPress to review the change.", 'pressocampus'),
                $display
            )
        );

        if (!$sent) {
            // Fall back to an admin notice (picked up by Onboarding::show_activation_notices).
            update_option('pressocampus_soul_update_notice', ['client_name' => $display]);
        }
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
    public function rebuild_index(int $user_id, string $host): void {
        $index_uri = self::get_index_uri($host);
        $count     = $this->resource_index->get_memory_count($user_id);
        $groups    = $this->resource_index->get_user_groups($user_id);
        $date      = current_time('Y-m-d H:i:s');

        $lines   = [];
        $lines[] = '# Memory Index';
        $lines[] = sprintf(
            'Last updated: %s | %d %s across %d %s',
            $date,
            $count,
            _n('memory', 'memories', $count, 'pressocampus'),
            count($groups),
            _n('group', 'groups', count($groups), 'pressocampus')
        );
        $lines[] = '';

        // Single query for all published memories — then group in PHP.
        // This replaces one WP_Query per group with a single query.
        $all_q = new \WP_Query([
            'post_type'      => PRESSOCAMPUS_CPT,
            'post_status'    => 'publish',
            'author'         => $user_id,
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_pressocampus_uri',
                    'compare' => 'EXISTS',
                ],
            ],
        ]);

        // Prime postmeta cache to avoid get_post_meta() N+1 queries below.
        if (!empty($all_q->posts)) {
            update_postmeta_cache(array_map(fn(\WP_Post $p): int => $p->ID, $all_q->posts));
        }

        // Group posts by taxonomy term in PHP using the already-warm term cache.
        $grouped = [];
        foreach ($all_q->posts as $memory) {
            $terms = get_the_terms($memory->ID, PRESSOCAMPUS_TAXONOMY);
            $slugs = is_array($terms) ? array_column($terms, 'slug') : [];
            foreach ($slugs as $slug) {
                $grouped[$slug][] = $memory;
            }
        }

        foreach ($groups as $group_slug) {
            $term        = get_term_by('slug', $group_slug, PRESSOCAMPUS_TAXONOMY);
            $group_label = ($term instanceof \WP_Term) ? $term->name : $group_slug;
            $group_posts = $grouped[$group_slug] ?? [];
            $group_count = count($group_posts);

            $lines[] = sprintf(
                '## %s (%d %s)',
                $group_label,
                $group_count,
                _n('memory', 'memories', $group_count, 'pressocampus')
            );

            foreach ($group_posts as $memory) {
                $uri     = (string) get_post_meta($memory->ID, '_pressocampus_uri', true);
                $age     = human_time_diff(strtotime($memory->post_modified), time());
                $lines[] = sprintf('- %s — %s — updated %s ago', $memory->post_title, $uri, $age);
            }

            $lines[] = '';
        }

        $index_content = implode("\n", $lines);

        // Update existing index post or create a new one.
        $existing = $this->get_index_post($user_id);

        if ($existing !== null) {
            wp_update_post([
                'ID'           => $existing->ID,
                'post_content' => $index_content,
            ]);
            $this->resource_index->upsert($existing->ID, $index_uri, $user_id, $index_content);
        } else {
            $post_id = wp_insert_post([
                'post_type'    => PRESSOCAMPUS_CPT,
                'post_status'  => 'publish',
                'post_author'  => $user_id,
                'post_title'   => __('Memory Index', 'pressocampus'),
                'post_content' => $index_content,
            ]);

            if (is_int($post_id) && $post_id > 0) {
                add_post_meta($post_id, '_pressocampus_uri',                 $index_uri,     true);
                add_post_meta($post_id, '_pressocampus_mime_type',           'text/markdown', true);
                add_post_meta($post_id, '_pressocampus_schema_version',      '1.0',           true);
                add_post_meta($post_id, '_pressocampus_annotation_priority', 'normal',        true);
                add_post_meta($post_id, '_pressocampus_confidence',          'high',          true);
                $this->resource_index->upsert($post_id, $index_uri, $user_id, $index_content);
            }
        }
    }
}
