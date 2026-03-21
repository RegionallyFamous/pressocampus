# Changelog

All notable changes to Pressocampus are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.1.2] — 2026-03-21

### Changed

- **Soul concept redesigned** — the Soul is now written by the AI, in its own voice, as a record of your relationship — not a settings document you fill out. "My human is a product designer who thinks in systems" rather than "I am a product designer who thinks in systems." The AI acknowledges its session discontinuity and writes to future instances of itself.
- **Soul template rewritten** — the empty template now has sections framed from the AI's perspective: `Who I Am`, `My Values`, `This Person`, `How We Work Together`, `What I Know`, `To Future Instances of Myself`.
- **`initialize` instructions updated** — `base_instructions` no longer frames the soul as "a behavioral contract." It frames the soul as the AI's identity file: notes it wrote about this person and this relationship, which it should apply because they're its own, not because it was commanded to. The empty-soul instructions now ask the AI to write in its own voice and acknowledge the session-discontinuity reality.
- **Documentation rewritten** — `docs/the-soul.md` fully rewritten to explain the new concept, including its origin in the soul document research, the session problem, and how the soul grows over time.
- **`README.md` and `readme.txt` updated** — Soul descriptions updated to reflect the new framing.

---

## [1.1.1] — 2026-03-21

### Fixed

- **Self-healing `/brain` rewrite** — the plugin now checks `$wp_rewrite->rules` directly on every request and auto-flushes if the `/brain` rule is missing from the compiled table. This catches caching-plugin clears, Permalink saves that ran before the plugin loaded, and any other scenario where the rule silently disappeared.
- **Block direct `/wp-json/` access to MCP endpoint** — the REST route now returns 404 for any request not arriving through the `/brain` pretty URL. The REST registration is internal plumbing for the rewrite; it is no longer a public fallback path.

---

## [1.1.0] — 2026-03-21

### Fixed

- **`update_soul` and `update_soul_section` missing from Claude.ai tool list** — rewrote tool descriptions to frame them as user profile/preference storage rather than AI-behaviour modification. Phrases like "override AI defaults" and "behavioral instructions for how the AI should communicate" were triggering Claude.ai's client-side safety filter, causing the two tools to be silently dropped from the visible tool list.
- **Plugin description** — updated to plain-language copy: "Give your AI a permanent memory — stored on your WordPress site, not locked inside any app."

---

## [1.0.25] — 2026-03-21

### Security

- **CSPRNG OAuth client IDs** — `uniqid()` replaced with `bin2hex(random_bytes(16))`. Client IDs are now 32-character cryptographically random hex strings rather than predictable time-based identifiers.
- **Bcrypt client secrets** — newly registered OAuth clients have their secrets hashed with `password_hash(PASSWORD_BCRYPT)` before storage; validation uses `password_verify()`. Existing clients with plaintext secrets continue to work transparently.
- **Rate limit on `initialize`** — the `initialize` handshake now counts against the read rate limit, preventing a loophole where repeated reconnections could trigger unbounded soul-creation DB work without being throttled.
- **Rate limit bypass closed** — `check_rate_limit()` previously returned `true` (allow) when a user was authenticated but had an empty `token_id`. It now returns `false` in that case, denying the request rather than silently bypassing all throttling.

### Fixed

- **`tool_forget` index desync** — `wp_delete_post()` return value is now checked before rewriting back-references or removing the index row. If the post cannot be deleted the tool returns an error immediately and the index stays in sync.
- **Back-reference rewrite ordering** — `rewrite_related_uri()` now runs after the post is confirmed deleted, not before, so it is never called for a post that still exists.
- **Uncaught `RuntimeException` in soul tools** — `tool_update_soul` and `tool_update_soul_section` now wrap `Soul::update()` and `Soul::update_section()` in a `try/catch` block. A soul-lock race (previously a 500) now returns a clean `soul_locked` tool error.

### Performance

- **`rebuild_index` no longer loads all posts into memory** — `WP_Query(posts_per_page=-1)` replaced with a batched 200-row direct SQL JOIN against the resource index table. Only the four columns actually needed (`ID`, `post_title`, `post_modified_gmt`, `uri`) are fetched.
- **`get_user_groups` single-query** — the previous two-step approach (`get_user_post_ids()` returning an unbounded int array → `get_terms(object_ids:)` building a potentially 1 000-item `IN` clause) is replaced by a single `JOIN` across `term_relationships`, `term_taxonomy`, `terms`, and the resource index. The now-unused `get_user_post_ids()` method has been removed.
- **`rewrite_related_uri` cache priming** — added an early-return when no back-references exist, and a `update_postmeta_cache()` call before the update loop to batch-prime postmeta, eliminating N+1 `get_post_meta` queries when multiple memories reference the deleted URI.

### Reliability

- **Atomic soul-creation lock** — `Soul::create()` replaces the `get_transient` / `set_transient` race window with `SELECT GET_LOCK()` (timeout 0). The MySQL advisory lock is connection-scoped: if the PHP process dies mid-creation the lock is automatically released, and no other process can enter the creation path simultaneously.
- **Atomic rebuild lock** — `ResourceIndex::rebuild_if_dirty()` uses the same `GET_LOCK` pattern, replacing the previous transient-based lock that had the same race window.

### Code quality

- **Rate-limit helpers** — the 9 duplicated `get_option('pressocampus_settings')['rate_limit_*']` + `tool_error()` blocks have been extracted to `write_rate_limit_error()` and `read_rate_limit_error()` private helpers. Fixes a hardcoded `'Read rate limit reached (60/min).'` message in `tool_search_memory` that ignored the configured limit.

---

## [1.0.24] — 2026-03-21

### Added

- **MCP spec 2025-11-25 compliance** — updated from protocol version `2025-03-26` to `2025-11-25`:
  - Version negotiation in `initialize`: the server now echoes the client's requested `protocolVersion` back if supported (`2025-03-26` or `2025-11-25`), rather than always returning the server's own version. Older clients remain fully compatible.
  - `MCP-Protocol-Version` request header is now validated on every POST. Unsupported values receive a `400 Bad Request` with the list of accepted versions.
  - `Origin` → `403 Forbidden` enforcement: when a browser `Origin` header is present but not in the configured allowlist, all handlers now return `403` as required for DNS-rebinding protection.
  - `GET /brain` returns `405 Method Not Allowed` (SSE not supported), signalling clients to use plain JSON responses only.
  - `Access-Control-Allow-Headers` now includes `MCP-Protocol-Version` and `MCP-Session-Id`.
  - `serverInfo` now includes a `title` field alongside `name`.
  - All successful tool results now carry `"isError": false` explicitly.

### Changed

- **Custom brain icon in the WordPress admin menu** — replaced the generic info-circle with a bespoke SVG brain icon. Designed to the Dashicons flat-icon style; renders cleanly at 20 px menu size.
- **Admin menu item moved to the bottom of the sidebar** — menu position changed from `30` (between Pages and Comments) to `100` (below the bottom separator, after Settings).

---

## [1.0.22] — 2026-03-21

### Added

- **First-install Quick Start card** — on activation, the admin is redirected to a three-step onboarding panel above the settings tabs. Step 1 shows the Brain URL with a copy button. Step 2 provides tabbed config snippets for Claude Desktop, Cursor / Windsurf, and generic MCP clients, each with a one-click copy button. Step 3 has an inline Test Connection button so the endpoint can be verified before switching to an AI client. The card dismisses cleanly via a `×` link and never reappears.

### Fixed

- **HTTP status propagation** — `tool_error()` now threads its `$status` argument through `dispatch_single()` to `handle()`, so a 409 ETag conflict returns HTTP 409 at the transport level rather than 200.
- **Duplicate detection bug** — the `possible_duplicate` field was always set to the first search result regardless of similarity. Renamed to `possible_related` and gated on ≥ 40% textual similarity. `possible_contradiction` threshold raised from 50% to 70% to prevent false positives on loosely related sentences.
- **Soul update email is now synchronous** — replaced `wp_schedule_single_event` with a direct `send_update_notice()` call so the email fires immediately rather than waiting for the next WP-Cron trigger (which could be hours on low-traffic sites).
- **Constant / header version mismatch** — `PRESSOCAMPUS_VERSION` was stuck at `1.0.20` while the plugin header read `1.0.21`; both are now `1.0.22`.

### Changed

- **`expires_at` support in `remember` tool** — the tool now accepts an ISO 8601 datetime (`expires_at`) and stores it as `_pressocampus_expires_at`. The existing expiry cron job now has values to act on.
- **`get_user_groups()` cached** — result is now stored in the object cache for 5 minutes and invalidated on every write via `mark_dirty()`, eliminating a full index-table scan on every MCP `initialize`.
- **Auth fallback path logged** — `validate_with_direct_resource_server()` now writes an `error_log()` warning when hit, making bootstrap injection failures visible in production logs.
- **Access token TTL raised** — `PRESSOCAMPUS_ACCESS_TOKEN_TTL` changed from `PT1H` (1 hour) to `PT8H` (8 hours). Users who return to Claude after a gap will no longer be forced to re-authorize mid-session.

---

## [1.0.21] — 2026-03-21

### Changed

- Rewrote admin UI copy to lead with ownership and permanence. Hero banner on the Connect tab now opens with "WordPress has been running since 2003. Your memories should last just as long." Soul status, empty states, export section, and History page header all updated with stronger, more direct language. Connect dropdown relabelled "Get config for…" with client icons (🤖 Claude Desktop, ⌨️ Cursor, ⚙️ Other).
- Synced `composer.json` version to match plugin header (was stuck at `1.0.4` from an earlier session).

---

## [1.0.22] — 2026-03-20

### Fixed
- **`/brain` rewrite rule missing after deactivate → reactivate** (`class-installer.php`, `class-plugin.php`). The activation hook was calling `flush_rewrite_rules()` at the end of `Installer::activate()`, but `add_rewrite_rule('^brain/?$', ...)` is registered on the `init` hook — which has not fired yet during activation. The flush compiled the rewrite table without the `/brain` rule. Subsequently, the version-change guard in `register_brain_rewrite()` correctly skipped a second flush because the stored version matched the installed version (same-version reactivation). The result was a permanent 404 until the admin manually visited Settings → Permalinks and saved. Fix: replace the direct `flush_rewrite_rules()` in `activate()` with a short-lived transient flag (`pressocampus_needs_flush`), consumed inside `register_brain_rewrite()` after `add_rewrite_rule()` has been called. Also `delete_option('pressocampus_plugin_version')` on activation so the version-change guard independently triggers a flush as a belt-and-suspenders fallback.

---

## [1.0.21] — 2026-03-20

### Changed
- Added `phpcs.xml` to align local PHPCS with the WordPress-Extra standard. Globally excludes unavoidable patterns with documented rationale: custom-table direct DB queries, schema-change migrations, table-name interpolation, dynamic IN-list placeholders, and `meta_query`/`tax_query` on indexed columns. No functional changes.

---

## [1.0.20] — 2026-03-21

### Changed

- **Documentation overhaul**: Rewrote `readme.txt` to be benefit-focused rather than technical.
- **MCP Tools Reference**: Added `list_memories` and `tag_memory` documentation; updated tool count from 6 to 8.
- **The Soul guide**: Updated soul snapshot size from 2 KB to 6 KB; added Session Briefing resource documentation.
- **Connecting Your AI guide**: Removed stale "Starter Prompt" section (server-side onboarding replaced the manual prompt in v1.0.17).
- **Admin Guide**: Removed stale "Starter Prompt" item from the Settings → Connect section.
- **README.md**: Updated docs table to reference 8 tools; added `list_memories` and Session Briefing to feature highlights.

---

## [1.0.19] — 2026-03-21

### Changed

- **Plugin Check compliance pass** — resolved all errors and warnings from the WordPress Plugin Check tool:
  - Replaced `heredoc` syntax in the OAuth consent page with `ob_start` / `ob_get_clean`.
  - Replaced all `parse_url()` calls in `class-psr7-bridge.php` with `wp_parse_url()`.
  - Added `wp_unslash()` + appropriate sanitization to every `$_SERVER`, `$_POST`, and `$_GET` access across `class-oauth-server.php`, `class-auth.php`, `class-mcp-endpoint.php`, `class-settings.php`, and `class-psr7-bridge.php`.
  - Added justified `phpcs:ignore` annotations for direct DB queries on custom tables, OAuth-protocol endpoints that cannot use WordPress nonces, and dynamic SQL table names that are provably safe.
  - Updated `readme.txt`: `Tested up to` bumped to 6.9; `Stable tag` corrected to `1.0.19`.
  - Updated plugin header: `Tested up to` bumped to 6.9.

---

## [1.0.18] — 2026-03-21

### Added

- **`list_memories` tool** — browse all stored memories without a search query. Supports `group` filter, `sort` (`date_desc` / `date_asc` / `name_asc`), `limit` (up to 50), and cursor-based pagination.
- **Session Briefing resource** (`pressocampus://[host]/briefing`) — a virtual, read-only resource pinned at the top of every `resources/list` response. Contains memory count, group breakdown, soul status, critical-priority memories, memories updated in the last 7 days, and stale candidates (not touched in 6+ months).
- **`tag_memory` tool** — change an existing memory's group and/or priority without touching its content.
- **Inline related content in `resources/read`** — when a memory has `related` URIs, the response now includes `annotations.related_content` with `{uri, name, excerpt}` for each linked memory belonging to the same user.

### Changed

- `instructions` field updated to mention `list_memories`, `tag_memory`, and the Session Briefing resource URI.

---

## [1.0.17] — 2026-03-21

### Changed

- **Soul truncation threshold raised 2 KB → 6 KB** — a well-developed soul will no longer be silently cropped. Truncated preview also increases from 500 → 1,500 chars.
- **`instructions` reacts to truncated souls** — when the snapshot was cut off, `initialize` now opens with an explicit `ACTION REQUIRED` directive telling the AI to call `resources/read` on the soul URI before responding.
- **Tool descriptions tightened** — `remember` now states "always call `search_memory` first" in its description; `search_memory` is reordered first in the list; `forget` clarifies it requires an explicit user instruction; `context` parameter changed from "optional" to "strongly recommended."
- **Starter Prompt removed from Settings → Connect** — the `initialize` handshake handles first-connection onboarding automatically.

---

## [1.0.16] — 2026-03-21

### Changed

- **Forceful soul-setup directive on first connection** — when `meta.soulStatus` is `"empty"`, the MCP `initialize` response now returns a numbered, imperative `ACTION REQUIRED` block telling the AI to call `update_soul`, greet the user, run the interview, and write the completed Soul — before doing anything else.

---

## [1.0.15] — 2026-03-21

### Fixed

- **OAuth endpoints now served via `/brain/oauth/*` bypass routes** handled directly in WordPress's `parse_request` hook, completely bypassing the REST API dispatcher (and any security plugin or server rule blocking `/wp-json/*`). All three endpoints bypassed: `/brain/oauth/register`, `/brain/oauth/authorize`, `/brain/oauth/token`.
- **Well-known metadata updated** — `/.well-known/oauth-authorization-server` now advertises the `/brain/oauth/*` bypass URLs.
- **Consent form action updated** — allow/deny form POSTs to `/brain/oauth/authorize` instead of `/wp-json/...`.
- **Diagnostics: registered routes check** — new check reports which Pressocampus REST routes are actually registered.
- **Diagnostics: OAuth endpoint checks updated** — tests the `/brain/oauth/*` bypass URL first, then `/wp-json/`, then `?rest_route=` fallback.

---

## [1.0.14] — 2026-03-21

### Fixed

- **RSA key pair is now auto-generated on every boot** if the option is missing. Extracted into `Installer::maybe_generate_rsa_keys()`, called from both `Installer::activate()` and `Plugin::__construct()`.
- **Diagnostics: auto-generate RSA keys inline** if missing at diagnostic run time.
- **Diagnostics: check `/wp-json/` rewrite rule** in addition to the `/brain` rule.
- **Diagnostics: test `?rest_route=` query-string fallback** for OAuth endpoints.

---

## [1.0.13] — 2026-03-21

### Fixed

- **Hard-flush `.htaccess` when auto-upgrading from plain permalinks.** v1.0.12 set the permalink option but only did a soft (DB-only) flush. The flush now uses `$hard = true` to regenerate `.htaccess` as well.
- **Diagnostics tab now checks `.htaccess`** on Apache hosts.

---

## [1.0.12] — 2026-03-21

### Fixed

- **Plain permalinks are now auto-upgraded to `/%postname%/`.** On activation and on every boot while plain permalinks are detected, the plugin sets `permalink_structure` to `/%postname%/` and flushes rewrite rules automatically.

---

## [1.0.11] — 2026-03-21

### Fixed

- **Plain-permalink sites now get a clear error** instead of a silent 404. The settings page shows a prominent notice with a direct link to Settings → Permalinks; the Diagnostics tab reports this as the first (blocking) check.

---

## [1.0.10] — 2026-03-21

### Added

- **Diagnostics tab** in the Pressocampus settings page. Performs a full end-to-end health check: PHP version, OpenSSL, all four database tables, RSA key pair, `/brain` rewrite rule, both `/.well-known` endpoints, MCP endpoint (401 + `WWW-Authenticate`), and both OAuth endpoints. Results are copyable as plain text for support requests.

---

## [1.0.9] — 2026-03-21

### Fixed

- **Plugin updates via file-copy no longer break the `/brain` URL or OAuth flow.** The plugin now auto-runs database migrations on every boot when the schema version is behind, and flushes rewrite rules once whenever the plugin version changes.
- **DCR rate limit raised from 10 to 50 registrations per IP per hour.**

---

## [1.0.8] — 2026-03-21

### Fixed

- **Bearer token auth on the `/brain` pretty-URL rewrite path.** When WordPress rewrites `/brain` to the REST route, `REQUEST_URI` stays as `/brain` — the auth filter checked only `REQUEST_URI` and never validated tokens. Fixed by also checking the `rest_route` query variable in both the authentication filter and the `WWW-Authenticate` header filter.

---

## [1.0.7] — 2026-03-21

### Fixed

- **"Authorization with the MCP server failed" on Claude / other MCP clients.** Three root causes:
  1. The `401 Unauthorized` response was missing the `WWW-Authenticate` header MCP clients require.
  2. The `/.well-known/oauth-protected-resource` document (RFC 9728) was not implemented.
  3. Missing RSA key pair caused the token endpoint to fail silently with a 500.
- Both `/.well-known` endpoints now return `Access-Control-Allow-Origin: *`.

---

## [1.0.6] — 2026-03-21

### Fixed

- **Fatal error on activation when OpenSSL extension is missing.** Added an explicit `extension_loaded('openssl')` check that deactivates the plugin with a clear error before any OpenSSL code runs.
- **Fatal error on OAuth / MCP connection attempt.** Four PSR-7 adapter classes were absent from the autoloader map. All four are now registered.
- **Admin settings JS extracted** to `assets/js/admin-settings.js` and properly enqueued via `wp_enqueue_script` + `wp_localize_script`.
- **`last_used` → `last_used_at` column alias bug** in the connected-apps query corrected.
- **WordPress.org `readme.txt`** added.

---

## [1.0.5] — 2026-03-21

### Fixed

- **"Security check failed. Please try again." on OAuth consent form submission.** WordPress nonces are tied to the current user ID. The REST API's cookie checker resets the user to 0 before the consent screen renders, so both nonces (`_pc_nonce` and `_wpnonce`) were being created for user 0. When the form was submitted, `wp_verify_nonce()` ran against the real authenticated user and always failed. Fixed by moving nonce creation to after the user is fully restored from the auth cookie.

---

## [1.0.4] — 2026-03-21

### Changed

- **Settings page migrated to WordPress core admin styles.** Replaced bespoke CSS and custom components with native WordPress equivalents: `nav-tab-wrapper`, `form-table`, `wp-list-table`, `notice notice-*`, `button`/`button-primary`, `tablenav`. Page now respects the admin colour scheme and dark mode.
- **MCP `initialize` instructions expanded** — fully describes Pressocampus, enumerates all tools with usage guidance, and includes an onboarding script for first-time users.

### Fixed

- `PRESSOCAMPUS_VERSION` constant corrected to track the actual release version (was hardcoded to `1.0.0`).

---

## [1.0.3] — 2026-03-21

### Fixed

- **OAuth consent form POST returned 403 `rest_cookie_invalid_nonce`.** The consent form stored our form-integrity nonce in `_wpnonce`. WordPress's REST cookie checker intercepts that field and validates it against the `wp_rest` action — causing 403 before our handler ran. Fixed by renaming our form nonce to `_pc_nonce` and adding a real `_wpnonce = wp_create_nonce('wp_rest')` field.

---

## [1.0.2] — 2026-03-21

### Fixed

- **OAuth consent screen unreachable after WordPress login.** WordPress's `rest_cookie_check_errors` called `wp_set_current_user(0)` on the redirect back from `wp-login.php` because no `X-WP-Nonce` header was present, causing `is_user_logged_in()` to return `false`. Fixed by calling `wp_validate_auth_cookie()` directly before the login check.

---

## [1.0.1] — 2026-03-21

### Fixed

- **Corrected 50 inaccuracies across all documentation files** — every claim verified against the actual source code. Key corrections: Brain endpoint URL, Claude Desktop config format, soul status values, MCP tool return shapes and error codes, RSA key storage (plaintext PEM, not sodium-encrypted), CORS behaviour (allowlist-based, not open), rate limit responses (tool errors, not HTTP 429), WP-CLI command interfaces.
- Updated plugin author to Regionally Famous.
- Added CI release workflow to attach plugin ZIP to GitHub releases.

---

## [1.0.0] — 2026-03-20

Initial public release.

### Added

#### Core memory store
- Custom Post Type `pressocampus_mem` stores every memory as a first-class WordPress post — portable, backupable, and never locked in a proprietary format.
- Per-user scoping enforced at the database query level: an AI authorised as User A can never read, write, or delete User B's memories.
- Memory limit per user (default 1,000); configurable in Settings → Advanced.
- Maximum memory size per entry (default 512 KB); configurable.
- Priority tiers — `critical`, `important`, `normal`, `low` — with `resources/list` returning memories sorted by priority.
- Confidence levels — `high`, `medium`, `low` — recorded on every stored memory.
- TTL / `expires_at` support: memories with a past expiry date are transitioned to `pressocampus_expired` status via WP-Cron and hidden from lists and search.
- ETag-based optimistic concurrency on all write operations: a stale ETag returns a `409 Conflict` rather than silently overwriting.
- Full-text search via a dedicated resource index table (`wp_pressocampus_resource_index`) with a `FULLTEXT` index on the excerpt column.
- Duplicate detection on `remember`: exact content-hash match returns a `note` instead of creating a duplicate; near-duplicate surfaces a `possible_duplicate` advisory.
- Contradiction detection on `remember`: subject-based comparison surfaces a `possible_contradiction` advisory.
- Related-memory pointers (`_pressocampus_related`), automatically rewritten when a referenced memory is deleted.

#### The Soul
- A permanent, protected per-user identity document stored at `pressocampus://yoursite.com/soul`.
- Delivered in the `initialize` handshake as `soul_snapshot`, `soul_etag`, and `soul_status` (`"empty"` or `"complete"`).
- The Soul and Index cannot be deleted via MCP tools or WP-CLI — attempts return `code: soul_protected`.
- `Status: empty` sentinel automatically stripped from the Soul after its first real update.

#### The Index
- Auto-maintained table of contents at `pressocampus://yoursite.com/index` listing memory groups, counts, and recent entries.
- Rebuilt automatically (debounced) whenever memories change.

#### MCP endpoint
- MCP 2025-03-26 compliant JSON-RPC 2.0 endpoint at `/wp-json/pressocampus/v1/mcp` (also accessible as `/brain` via rewrite rule).
- Six tools: `remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, `search_memory`.
- Rate limiting: 60 reads/min and 30 writes/min per user, backed by WordPress object cache with transient fallback.
- `/.well-known/oauth-authorization-server` and `/.well-known/oauth-protected-resource` discovery endpoints.
- CORS allowlist: `Access-Control-Allow-Origin` is only set for origins in the configured allowlist or the site's own origin.

#### OAuth 2.1
- Full Authorization Code flow with PKCE (S256) implemented via `league/oauth2-server`.
- Dynamic client registration (`POST /oauth/register`) — AI clients self-register; no manual app setup required.
- RSA 2048-bit key pair generated on activation; stored as plaintext PEM in `wp_options`. Separate `defuse/php-encryption` key used to encrypt OAuth token payloads.
- Access tokens: JWT, RSA-signed, 1-hour lifetime (configurable via `PRESSOCAMPUS_ACCESS_TOKEN_TTL`).
- Refresh tokens: opaque, stored hashed, rotated on every use, 30-day lifetime (configurable via `PRESSOCAMPUS_REFRESH_TOKEN_TTL`).
- Authorization codes: 10-minute lifetime (configurable via `PRESSOCAMPUS_AUTH_CODE_TTL`).
- Consent screen with full i18n support.

#### Admin UI
- **Settings → Connect**: Brain URL with one-click copy, config snippets for Claude Desktop / Cursor / generic MCP, Soul status indicator, Test Connection button.
- **Settings → Advanced**: Connected Apps table with per-client revoke, CORS origin allowlist, rate limit fields, memory limit, max memory size, Download Brain (exports all memories as `pressocampus-brain.json`).
- **History**: audit log with human-readable action labels, searchable by action type and agent name, linked to memory URI.
- Admin notices: Soul placeholder reminder, token expiry warning (7 days before expiry), soul-updated notification, domain migration reminder.
- Onboarding redirect to Settings → Connect on first activation.

#### Infrastructure
- `Installer::activate()`: runtime checks for PHP ≥ 8.3 and WordPress ≥ 6.4 with graceful `wp_die()` on failure.
- `vendor/autoload.php` missing check: plugin self-deactivates with an admin notice rather than fatal-erroring.
- Database schema with `dbDelta()`-managed tables for OAuth clients, tokens, auth codes, refresh tokens; resource index; and audit log. Schema versioned at `1.2`.
- Three WP-Cron events: `pressocampus_check_token_expiry` (daily), `pressocampus_expire_memories` (hourly), `pressocampus_purge_audit_log` (weekly).
- `uninstall.php` deletes all memories, OAuth data, audit log, custom tables, and all plugin options.
- WP-CLI command suite: `list`, `get`, `delete`, `export` (JSON + Markdown folder), `import`, `migrate-domain`, `flush-cache`, `audit`, `stats`.
- GitHub Actions CI pipeline: PHPCS (WordPress Coding Standards), PHPStan level 6, PHPUnit 12 matrix (PHP 8.3 + 8.4 × WordPress 6.7 + latest), distributable zip build on merge to `main`.
- Custom `Pressocampus\Tests\TestCase` base class that bypasses `WP_UnitTestCase` entirely while replicating DB-transaction isolation and `WP_UnitTest_Factory` access.

### Security
- OAuth 2.1 + PKCE as the sole authentication method — no API keys, no plaintext credentials in config files.
- `user_id = get_current_user_id()` guard on all `DELETE` queries in `ajax_revoke_client()` to prevent cross-user revocation.
- `set_test_user()` and `clear_test_user()` in `Auth` gated behind `defined('PRESSOCAMPUS_TESTING')` to prevent use in production.
- Broad `\Throwable` catch blocks in OAuth handlers and MCP Soul calls return clean error responses rather than raw 500s.

---

[1.0.0]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.0
[1.0.1]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.1
[1.0.2]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.2
[1.0.3]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.3
[1.0.4]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.4
[1.0.5]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.5
[1.0.6]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.6
[1.0.7]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.7
[1.0.8]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.8
[1.0.9]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.9
[1.0.10]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.10
[1.0.11]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.11
[1.0.12]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.12
[1.0.13]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.13
[1.0.14]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.14
[1.0.15]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.15
[1.0.16]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.16
[1.0.17]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.17
[1.0.18]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.18
[1.0.19]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.19
[1.0.20]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.20
[1.0.21]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.21
[1.0.22]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.22
[1.0.24]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.24
[1.0.25]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.25
