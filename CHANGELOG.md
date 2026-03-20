# Changelog

All notable changes to Pressocampus are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).
Versioning follows [Semantic Versioning](https://semver.org/).

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
