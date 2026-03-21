# Changelog

All notable changes to Pressocampus are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

---

## [1.0.20] — 2026-03-20

### Changed
- **Documentation overhaul**: Rewrote `readme.txt` to be benefit-focused rather than technical; updated changelog to include all versions through 1.0.20.
- **MCP Tools Reference**: Added `list_memories` and `tag_memory` documentation; updated tool count from 6 to 8.
- **The Soul guide**: Updated soul snapshot size from 2 KB to 6 KB; added Session Briefing resource documentation.
- **Connecting Your AI guide**: Removed stale "Starter Prompt" section (server-side onboarding replaced the manual prompt in v1.0.17).
- **Admin Guide**: Removed stale "Starter Prompt" item from the Settings → Connect section.
- **README.md**: Updated docs table to reference 8 tools; added `list_memories` and Session Briefing to feature highlights.

---

## [1.0.19] — 2026-03-20

### Changed
- **Plugin Check compliance pass** — resolved all errors and warnings from the WordPress Plugin Check tool:
  - Replaced `heredoc` syntax in the OAuth consent page with `ob_start` / `ob_get_clean` (`PluginCheck.CodeAnalysis.Heredoc.NotAllowed`).
  - Replaced all `parse_url()` calls in `class-psr7-bridge.php` with `wp_parse_url()` to align with WordPress coding standards.
  - Added `wp_unslash()` + appropriate sanitization (`sanitize_text_field`, `sanitize_key`, `sanitize_mime_type`, `esc_url_raw`, `absint`) to every `$_SERVER`, `$_POST`, and `$_GET` access across `class-oauth-server.php`, `class-auth.php`, `class-mcp-endpoint.php`, `class-settings.php`, and `class-psr7-bridge.php`.
  - Added justified `phpcs:ignore` annotations for direct DB queries on custom tables (no WP API alternative), schema migration `ALTER TABLE` statements, OAuth-protocol endpoints that cannot use WordPress nonces, and dynamic SQL table names that are provably safe (`$wpdb->prefix + literal`).
  - Updated `readme.txt`: `Tested up to` bumped to 6.9; `Stable tag` corrected to `1.0.19`.
  - Updated plugin header: `Tested up to` bumped to 6.9.

---

## [1.0.18] — 2026-03-21

### Added

- **`list_memories` tool** — browse all stored memories without needing a search query. Supports `group` filter, `sort` (`date_desc` / `date_asc` / `name_asc`), `limit` (up to 50), and cursor-based pagination. Returns URI, name, group, timestamps, priority, confidence, and a 20-word excerpt per item. Complements `search_memory` for periodic audits and session warm-up.
- **Session Briefing resource** (`pressocampus://[host]/briefing`) — a virtual, read-only resource pinned at the top of every `resources/list` response. Reading it generates a fresh Markdown document containing: memory count and group breakdown, soul status, critical-priority memories with excerpts, memories updated in the last 7 days, and memories not touched in 6+ months (stale candidates for review). Referenced by name in the system `instructions` alongside the soul snapshot.
- **`tag_memory` tool** — change an existing memory's group and/or priority without touching its content. Useful for reorganising memories as they accumulate, merging orphaned items into groups, or promoting key memories to `critical`. Guards against accidentally retagging the soul or index.
- **Inline related content in `resources/read`** — when a memory has `related` URIs, the read response now includes `annotations.related_content`: an array of `{uri, name, excerpt}` for each linked memory that belongs to the same user. The AI no longer needs to make N extra read calls to follow relationships.

### Changed

- `instructions` field updated to mention `list_memories`, `tag_memory`, and the Session Briefing resource URI.

---

## [1.0.17] — 2026-03-21

### Changed

- **Soul truncation threshold raised 2 KB → 6 KB** — a well-developed soul will no longer be silently cropped. The truncated preview also increases from 500 → 1 500 chars so the AI still has meaningful context while it fetches the rest.
- **`instructions` reacts to truncated souls** — when the snapshot was cut off, the `initialize` response now opens with an explicit `ACTION REQUIRED` directive telling the AI to call `resources/read` on the soul URI before responding. Previously it just set `meta.soul_truncated: true` and hoped the AI noticed.
- **Tool descriptions tightened across the board**:
  - `remember`: "always call `search_memory` first" is now in the description, not just the system instructions. Notes that the server flags possible duplicates in the response.
  - `search_memory`: reordered to appear first in the list; description now explicitly says "call before `remember`".
  - `forget`: "do not infer deletion from tone or context — wait for the user to name the specific thing."
  - `context` parameter on all write tools: changed from "optional" to "strongly recommended — powers the History log."
  - `update_soul_section` / `update_soul`: clarified when each should be used.
- **Starter prompt removed from Settings → Connect** — the `initialize` handshake now handles first-connection onboarding automatically. Having a manual copy-paste step alongside the automatic signal was confusing and implied the server-side approach might not work.

---

## [1.0.16] — 2026-03-21

### Changed

- **Forceful soul-setup directive on first connection** — when `meta.soulStatus` is `"empty"`, the MCP `initialize` response now returns a different `instructions` value that leads with a numbered, imperative ACTION REQUIRED block. The AI is told explicitly to (1) call `update_soul` with the template, (2) greet the user, (3) run the interview, and (4) write the completed Soul — before doing anything else. Previously this guidance was buried mid-paragraph in the general instructions and could be deprioritised.

---

## [1.0.15] — 2026-03-21

### Fixed

- **OAuth endpoints now served via `/brain/oauth/*` bypass routes** that are handled directly in WordPress's `parse_request` hook — the same mechanism that makes `/brain` work. This completely bypasses the REST API dispatcher (and any security plugin or server rule that blocks `/wp-json/*` for unauthenticated requests). All three endpoints are bypassed: `/brain/oauth/register`, `/brain/oauth/authorize`, `/brain/oauth/token`.
- **Well-known metadata updated** — `/.well-known/oauth-authorization-server` now advertises the `/brain/oauth/*` bypass URLs as `authorization_endpoint`, `token_endpoint`, and `registration_endpoint`. Claude will use these URLs for the entire OAuth flow.
- **Consent form action updated** — the allow/deny form POSTs to `/brain/oauth/authorize` instead of `/wp-json/...`, so form submission cannot be blocked.
- **Diagnostics: registered routes check** — new check reports which Pressocampus REST routes are actually registered in WordPress's route table, making it easy to see whether a security plugin is removing routes after registration.
- **Diagnostics: OAuth endpoint checks updated** — tests the `/brain/oauth/*` bypass URL first, then the `/wp-json/` URL, then the `?rest_route=` fallback. The primary check is now the one Claude actually uses.

---

## [1.0.14] — 2026-03-21

### Fixed

- **RSA key pair is now auto-generated on every boot** if the option is missing. Extracted into `Installer::maybe_generate_rsa_keys()`, called from both `Installer::activate()` and `Plugin::__construct()`. Previously a missing key would only be regenerated when the OAuth server was first used (too late for a clean token exchange).
- **Diagnostics: auto-generate RSA keys inline** if missing at the time the diagnostic is run, so re-running diagnostics immediately shows the corrected state.
- **Diagnostics: check `/wp-json/` rewrite rule** in addition to the `/brain` rule.
- **Diagnostics: test `?rest_route=` query-string fallback** for OAuth endpoints. If the pretty `/wp-json/` URL returns 404 but the query-string form works, the report now says exactly what Nginx `location` block is needed to fix the routing.

---

## [1.0.13] — 2026-03-21

### Fixed

- **Hard-flush `.htaccess` when auto-upgrading from plain permalinks.** v1.0.12 set the permalink option but only did a soft flush (DB only). On Apache, the `.htaccess` rewrite rules must also be written for `/wp-json/` and `/brain` to route correctly. The flush now uses `$hard = true` when it detects and fixes a plain-permalink site, which regenerates `.htaccess` in addition to the DB.
- **Diagnostics tab now checks `.htaccess`** on Apache hosts, showing whether the file exists and contains WordPress rewrite rules.

---

## [1.0.12] — 2026-03-21

### Fixed

- **Plain permalinks are now auto-upgraded to `/%postname%/`.** The plugin cannot function without the WordPress rewrite engine — `/brain` and all OAuth REST endpoints require pretty permalinks. On activation and on every subsequent boot while plain permalinks are detected, the plugin now automatically sets `permalink_structure` to `/%postname%/` and flushes rewrite rules. No manual step required.

---

## [1.0.11] — 2026-03-21

### Fixed

- **Plain-permalink sites now get a clear error** instead of a silent 404 on `/brain` and all OAuth REST endpoints. The settings page shows a prominent notice with a direct link to Settings → Permalinks when plain permalinks are detected. The Diagnostics tab also reports this as the first (blocking) check.

---

## [1.0.10] — 2026-03-21

### Added

- **Diagnostics tab** in the Pressocampus settings page. Click "Run Diagnostics" to perform a full end-to-end health check: PHP version, OpenSSL extension, all four database tables, RSA key pair, `/brain` rewrite rule, `/.well-known/oauth-authorization-server`, `/.well-known/oauth-protected-resource`, the MCP endpoint (401 + `WWW-Authenticate`), and both OAuth endpoints. Results show pass/warn/fail for each step with detailed output. A "Copy Report" button formats everything as plain text to share when asking for support.

---

## [1.0.9] — 2026-03-21

### Fixed

- **Plugin updates via file-copy (FTP/ZIP upload) no longer break the `/brain` URL or OAuth flow.** Previously, only a full deactivate → reactivate cycle would create the database tables and flush the WordPress rewrite rules. If a user updated the plugin by uploading new files without deactivating, the `/brain` URL returned 404 and OAuth tokens could not be stored. The plugin now auto-runs database migrations on every boot when the schema version is behind, and automatically flushes rewrite rules once whenever the plugin version changes. No manual reactivation is needed after an update.
- **DCR rate limit raised from 10 to 50 registrations per IP per hour.** After multiple failed authorization attempts (each of which can trigger a new OAuth client registration from Claude's servers), the old limit could be exhausted, silently blocking further connection attempts.

---

## [1.0.8] — 2026-03-21

### Fixed

- **"Authorization with the MCP server failed" — root cause identified and fixed.** When an AI client uses the `/brain` pretty-URL shortcut (e.g. `https://yoursite.com/brain`), WordPress's rewrite rule sets `rest_route=/pressocampus/v1/mcp` but leaves `$_SERVER['REQUEST_URI']` as `/brain`. The Bearer-token authentication filter checked only `REQUEST_URI`, so it never saw the correct namespace, never validated the token, and every authenticated request returned 401 — even after the user had completed OAuth successfully. Claude interpreted the perpetual 401 as an authorization failure. Fixed by also checking the `rest_route` query variable (populated by the rewrite) in both the authentication filter and the `WWW-Authenticate` header filter.

---

## [1.0.7] — 2026-03-21

### Fixed

- **"Authorization with the MCP server failed" on Claude / other MCP clients.** Three root causes fixed:
  1. The `401 Unauthorized` response was missing the `WWW-Authenticate` header that MCP clients require to discover the OAuth authorization server. All 401s from the `/mcp` endpoint now include `WWW-Authenticate: Bearer realm="…", resource_metadata="…"`.
  2. The `/.well-known/oauth-protected-resource` document (RFC 9728) was not implemented. MCP clients resolve this URL first (from the `WWW-Authenticate` header) to find the authorization server. The endpoint now returns the correct resource and authorization server metadata.
  3. If the RSA key pair was never generated (because an earlier activation failure left it blank), the token endpoint would fail silently with a 500. `get_authorization_server()` now auto-generates the key pair on first call if it is missing.
- Both `/.well-known` endpoints now return `Access-Control-Allow-Origin: *` so browser-based OAuth discovery tools can fetch them.

---

## [1.0.6] — 2026-03-21

### Fixed

- **Fatal error on activation when OpenSSL extension is missing.** `OPENSSL_KEYTYPE_RSA` is a constant provided by the PHP `openssl` extension. Referencing it when the extension is absent throws an `Error` in PHP 8.x (rather than the notice/warning of older PHP versions), causing WordPress to report "Plugin could not be activated because it triggered a fatal error." Fixed by adding an explicit `extension_loaded('openssl')` check in `Installer::activate()` that deactivates the plugin and shows a clear error before any OpenSSL code runs.
- **Fatal error on any OAuth / MCP connection attempt.** The four PSR-7 adapter classes in `includes/oauth/class-psr7-bridge.php` (`WPStream`, `WPUri`, `WPResponse`, `WPServerRequest`) were absent from the plugin's autoloader map, so PHP could not find them when the OAuth authorization and token handlers ran — producing a "Class not found" fatal. All four classes are now registered in the autoloader.
- **Admin settings JS extracted to `assets/js/admin-settings.js`** and properly enqueued via `wp_enqueue_script` + `wp_localize_script`, removing ~150 lines of inline `<script>` from the PHP template.
- **`last_used` → `last_used_at` column alias bug** in the connected-apps query corrected.
- **`wp_unslash()` added** to all `$_GET` reads on the settings page.
- **WordPress.org `readme.txt`** added.

---

## [1.0.5] — 2026-03-21

### Fixed

- **"Security check failed. Please try again." on OAuth consent form submission.** WordPress nonces are tied to the current user ID. The REST API's cookie checker (`rest_cookie_check_errors`) resets the user to 0 when no `X-WP-Nonce` header is present — which is always the case after a redirect from `wp-login.php`. The consent form was calling `wp_create_nonce()` before the auth-cookie restoration, generating a nonce for user 0. When the form was submitted `wp_verify_nonce()` ran against the real authenticated user and always failed. Fixed by moving both nonce creations (`_pc_nonce` and `_wpnonce`) to after the user has been fully restored from the auth cookie.

---

## [1.0.4] — 2026-03-21

### Changed

- **Settings page now uses WordPress core admin styles exclusively.** Replaced 60+ lines of bespoke CSS and all custom component classes with native WordPress equivalents: `nav-tab-wrapper`/`nav-tab` for tabs, `form-table` for settings rows, `wp-list-table widefat` for data tables, `notice notice-*` for alerts, `button`/`button-primary`/`button-link-delete` for actions, and `tablenav` for the History filters and pagination. The page now respects the user's chosen admin colour scheme, dark mode, and accessibility settings correctly. Custom CSS is now minimal — only the tab panel toggle, action badge colours, share dropdown, and toast notification remain as plugin-specific styles.
- **MCP `initialize` instructions expanded.** The `instructions` field returned on every connection now fully describes what Pressocampus is, enumerates all six tools with usage guidance, and includes an explicit onboarding script for first-time users (greet, explain capabilities, interview, write soul). Previously a single sentence of behavioural hints.
- **Starter Prompt updated.** The copyable first message on the Connect tab now asks the AI to introduce itself and explain what it can do with the memory store before reading the soul, giving new users an immediate self-guided introduction.

### Fixed

- `PRESSOCAMPUS_VERSION` constant corrected to track the actual release version (was hardcoded to `1.0.0` since initial release).

---

## [1.0.21] — 2026-03-21

### Changed

- Rewrote all admin UI copy to lead with ownership and permanence. Hero banner on the Connect tab now opens with "WordPress has been running since 2003. Your memories should last just as long." Consent screen, soul status, empty states, export section, and History page header all updated with stronger, more direct language. Connect dropdown relabeled "Get config for…" with client icons.

---

## [1.0.3] — 2026-03-19

### Fixed

- **OAuth consent form submission returned 403 `rest_cookie_invalid_nonce`.** The consent form used `_wpnonce` for our own form-integrity nonce (`pressocampus_authorize_<client_id>` action). WordPress's REST cookie checker intercepts any `_wpnonce` POST parameter and validates it against the `wp_rest` action — causing a 403 before our handler ran. Fix: renamed our form nonce to `_pc_nonce` and added a separate `_wpnonce = wp_create_nonce('wp_rest')` field so the REST checker passes. Also applied the same auth-cookie restoration fix from 1.0.2 to the POST handler.

---

## [1.0.2] — 2026-03-19

### Fixed

- **OAuth consent screen unreachable after WordPress login.** When the authorization flow redirected an unauthenticated user to `wp-login.php`, WordPress's REST API cookie checker (`rest_cookie_check_errors`) called `wp_set_current_user(0)` on the redirect back because no `X-WP-Nonce` header was present. This caused `is_user_logged_in()` to return `false` even for a freshly authenticated user, sending them to the admin dashboard instead of the consent screen. Fixed by calling `wp_validate_auth_cookie()` directly before the login check — the OAuth consent flow has its own CSRF protection via the `state` parameter, so the nonce requirement does not apply.

---

## [1.0.1] — 2026-03-19

### Fixed

- Corrected 50 inaccuracies across all documentation files — every claim is now verified against the actual source code:
  - `admin-guide.md`: Brain endpoint URL, Claude Desktop config format (uses `npx mcp-remote`), soul states (`empty`/`complete`), Test Connection fires `ping` not `initialize`, History table rendering, CORS allowlist behavior, 512 KB default content size, export format (single JSON), import is WP-CLI only
  - `connecting-your-ai.md`: Claude Desktop and Cursor config snippets
  - `development.md`: Test file name and `PRESSOCAMPUS_DB_VERSION` constant name
  - `mcp-tools-reference.md`: `remember` return shape, `forget` error code (`soul_protected`), rate limit error code (`rate_limit_exceeded`), ETag conflict code (`etag_conflict`), `search_memory` response shape (`{results, count}`)
  - `memories.md`: MIME type is always `text/markdown`; `remember` has no `expires_at` parameter; export format
  - `security.md`: RSA key is stored as plaintext PEM; CORS is allowlist-based; rate limits return tool errors, not HTTP 429
  - `the-soul.md`: Starter template text; `soulStatus` value is `"complete"` not `"exists"`; soul cannot be deleted via WP-CLI either
  - `troubleshooting.md`: CPT slug is `pressocampus_mem`
  - `wp-cli-reference.md`: `list` output columns, `export` default filenames, `import` flag behavior, `audit` uses `--days`, `stats` actual output format, `migrate-domain` has no `--yes` flag
- Updated plugin author to Regionally Famous
- Added CI release workflow to attach plugin ZIP to GitHub releases

---

## [1.0.0] — 2026-03-20

Initial public release.

### Added

#### Core memory store
- Custom Post Type `pressocampus_mem` stores every memory as a first-class WordPress post — portable, backupable, and never locked in a proprietary format.
- Per-user scoping enforced at the database query level: an AI authorised as User A can never read, write, or delete User B's memories.
- Memory limit per user (default 1,000); configurable in Settings → Advanced.
- Maximum memory size per entry (default 64 KB); configurable.
- Priority tiers — `critical`, `important`, `normal`, `low` — with `resources/list` returning memories sorted by priority so the most important context loads first.
- Confidence levels — `high`, `medium`, `low` — recorded on every stored memory.
- TTL / `expires_at` support: memories with a past expiry date are automatically transitioned to a `pressocampus_expired` status via WP-Cron and hidden from lists and search.
- ETag-based optimistic concurrency on all write operations: a stale ETag returns a `409 Conflict` rather than silently overwriting.
- Full-text search via a dedicated resource index table (`wp_pressocampus_resource_index`) with a `FULLTEXT` index on the excerpt column.
- Duplicate detection on `remember`: content-hash comparison against existing memories for the same user surfaces a `possible_duplicate` warning before creating a near-identical entry.
- Contradiction detection on `remember`: subject-based comparison flags memories that may conflict with what's already stored.
- Related-memory pointers (`_pressocampus_related`), automatically rewritten when a referenced memory is deleted.

#### The Soul
- A permanent, protected per-user identity document stored at `pressocampus://yoursite.com/soul`.
- Delivered in the `initialize` handshake as `soul_snapshot`, `soul_etag`, and `soul_status` so every AI has full context before sending its first message.
- The Soul and Index cannot be deleted via MCP tools — attempts return `code: soul_protected`.
- Up to 20 revisions retained for the Soul (other memories cap at 5).
- `Status: empty` sentinel automatically stripped from the Soul after its first real update.

#### The Index
- Auto-maintained table of contents at `pressocampus://yoursite.com/index` listing memory groups, counts, and recent entries.
- Rebuilt automatically (debounced) whenever memories change.

#### MCP endpoint
- MCP 2025-03-26 compliant JSON-RPC 2.0 endpoint at `/wp-json/pressocampus/v1/mcp` (also accessible as `/brain` via rewrite rule).
- Six tools: `remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, `search_memory`.
- Rate limiting: 60 reads/min and 30 writes/min per user, backed by WordPress object cache with transient fallback. Error messages include the actual configured limit values.
- `/.well-known/oauth-authorization-server` and `/.well-known/mcp.json` discovery endpoints.
- CORS with Origin reflection; configurable origin allowlist in Settings → Advanced.

#### OAuth 2.1
- Full Authorization Code flow with PKCE (S256) implemented via `league/oauth2-server`.
- Dynamic client registration (`POST /oauth/register`) — AI clients self-register; no manual app setup required.
- RSA 2048-bit key pair generated on activation; private key encrypted with `sodium_crypto_secretbox` (via `defuse/php-encryption`) and stored in `wp_options`. Never exported.
- Access tokens: JWT, RSA-signed, 1-hour lifetime (configurable via `PRESSOCAMPUS_ACCESS_TOKEN_TTL`).
- Refresh tokens: opaque, stored hashed, rotated on every use, 30-day lifetime (configurable via `PRESSOCAMPUS_REFRESH_TOKEN_TTL`).
- Authorization codes: 10-minute lifetime (configurable via `PRESSOCAMPUS_AUTH_CODE_TTL`).
- Consent screen with full i18n support via `esc_html__()` / `esc_attr__()` throughout.

#### Admin UI
- **Settings → Connect**: Brain Endpoint URL with one-click copy, Share Brain snippets for Claude Desktop / Cursor / generic MCP, Starter Prompt, Soul status indicator, Test Connection button.
- **Settings → Advanced**: Connected Apps table (App / Connected / Last used columns) with per-user revoke, CORS settings with permissive-behaviour warning, rate limit fields with help text, memory limit, max memory size, Download Brain (exports Soul + Index + memories as ZIP), Import, DISABLE_WP_CRON notice, uninstall data option.
- **History**: audit log with human-readable action labels (Saved memory, Deleted memory, Updated memory, Updated soul, Updated soul section, Searched memories), searchable by action and agent, linked to memory URI.
- Admin notices: Soul placeholder reminder, token expiry warning (sent to resource owner, 7 days before expiry), mass-delete detection (10+ deletes in a session), soul-updated notification, domain migration reminder.
- Onboarding redirect to Settings → Connect on first activation.

#### Infrastructure
- `Installer::activate()`: runtime checks for PHP ≥ 8.3 and WordPress ≥ 6.4 with graceful `wp_die()` on failure.
- `vendor/autoload.php` missing check: plugin self-deactivates with an admin notice rather than fatal-erroring.
- Database schema with `dbDelta()`-managed tables for OAuth clients, tokens, auth codes, refresh tokens; resource index; and audit log. Schema versioned at `1.2`.
- Audit log indexes on `action` and `oauth_client_name` columns; version-gated `ALTER TABLE` migration applies them to existing installs.
- Three WP-Cron events: `pressocampus_check_token_expiry` (daily), `pressocampus_expire_memories` (hourly), `pressocampus_purge_audit_log` (weekly). All three are cleared via `wp_clear_scheduled_hook()` on deactivation.
- `uninstall.php` deletes all memories, OAuth data, audit log, custom tables, all plugin options (including dynamic `pressocampus_expiry_notice_*` entries), and the `pressocampus_service` user when "Delete all data" is checked.
- WP-CLI command suite: `list`, `get`, `delete`, `export` (JSON + Markdown folder), `import`, `migrate-domain`, `flush-cache`, `audit`, `stats`.
- GitHub Actions CI pipeline: PHPCS (WordPress Coding Standards), PHPStan level 6, PHPUnit 12 matrix (PHP 8.3 + 8.4 × WordPress 6.7 + latest), distributable zip build on merge to `main`.
- Custom `Pressocampus\Tests\TestCase` base class that bypasses `WP_UnitTestCase` entirely (and thus the removed `PHPUnit\Util\Test::parseTestMethodAnnotations()`) while replicating DB-transaction isolation and `WP_UnitTest_Factory` access.

### Security

- OAuth 2.1 + PKCE as the sole authentication method — no API keys, no plaintext credentials in config files.
- `wp_redirect()` used (not `wp_safe_redirect()`) in the OAuth authorize handler so external AI client redirect URIs are accepted.
- Operator precedence bug in confidential-client detection fixed at release: `(bool)` cast removed so `$is_confidential` correctly reflects `token_endpoint_auth_method === 'client_secret_post'`.
- Token expiry emails delivered to the resource owner's address, not the site admin's. Token ID never included in the email body or stored option.
- `user_id = get_current_user_id()` guard added to both `DELETE` queries in `ajax_revoke_client()` to prevent cross-user revocation.
- `set_test_user()` and `clear_test_user()` in `Auth` gated behind `defined('PRESSOCAMPUS_TESTING')` to prevent use in production.
- Broad `\Throwable` catch blocks in OAuth handlers (`handle_authorize_submit`, `handle_token`) and MCP Soul calls return clean error responses rather than raw 500s on key-loading or initialization failures.

---

[1.0.0]: https://github.com/RegionallyFamous/pressocampus/releases/tag/v1.0.0
