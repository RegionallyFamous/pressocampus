# Changelog

All notable changes to Pressocampus are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
