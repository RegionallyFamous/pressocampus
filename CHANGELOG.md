# Changelog

All notable changes to Pressocampus are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
