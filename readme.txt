=== Pressocampus ===
Contributors: regionallyfamous
Tags: ai, memory, mcp, claude, chatgpt
Requires at least: 6.4
Tested up to: 6.7
Stable tag: 1.0.5
Requires PHP: 8.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WordPress into your AI's persistent memory store via the Model Context Protocol (MCP).

== Description ==

Pressocampus turns WordPress into a persistent memory store for AI assistants that support the Model Context Protocol (MCP). It exposes a secure MCP endpoint at `/brain` and handles all the OAuth 2.1 authentication so your AI client (Claude, Cursor, ChatGPT, or any MCP-compatible tool) can remember things across sessions.

**What it does:**

* Stores AI memories as a private custom post type in your WordPress database
* Exposes a fully-compliant MCP 2025-03-26 endpoint over HTTPS
* Handles OAuth 2.1 authorization — no API keys to manage
* Lets you write and read a persistent "Soul" note your AI reads at the start of every session
* Logs every memory action in a searchable History page
* Cleans up fully on uninstall — no data left behind

**AI clients tested:**

* Claude Desktop
* Cursor
* Any client that supports the `mcp-remote` npm bridge

== Installation ==

1. Upload the plugin ZIP to **Plugins → Add New → Upload Plugin** and activate it.
2. On activation the plugin creates its database tables and a dedicated service user automatically.
3. Go to **Pressocampus → Settings** and copy your Brain URL.
4. Paste the Brain URL into your AI client's MCP server configuration and start a new conversation.

**Requirements:** PHP 8.3+ and WordPress 6.4+.

== Frequently Asked Questions ==

= Does this send my data to any third-party service? =

No. All memories are stored in your own WordPress database. Pressocampus does not send your data anywhere — it only exposes an endpoint that your AI client connects to directly.

= Which AI clients are supported? =

Any client that supports the Model Context Protocol (MCP 2025-03-26) over HTTP with OAuth 2.1. Claude Desktop, Cursor, and other clients that use `mcp-remote` are all compatible.

= Is the Brain endpoint public? =

The endpoint is publicly reachable (required by MCP) but OAuth 2.1 protects all memory operations. An AI client must complete the OAuth flow before it can read or write memories.

= What PHP extensions are required? =

PHP 8.3+, `openssl` (for RSA key generation), and `sodium` (recommended for encryption). The plugin will warn you in the admin if either is missing.

= Does uninstalling remove my data? =

Yes. Deleting the plugin removes all database tables, all memory posts, all plugin options, and the service user created on activation.

== Changelog ==

= 1.0.5 =
* Updated league/oauth2-server to v9.3 (OAuth 2.1 strict typing).
* Updated phpunit/phpunit to v12 and yoast/phpunit-polyfills to v4.
* Fixed PHPStan memory exhaustion caused by duplicate stub loading.
* Added GitHub Actions release workflow to attach plugin ZIP to releases.

= 1.0.4 =
* Added FULLTEXT and composite indexes to resource index table for faster search.
* Added action and oauth_client_name indexes to audit log table.

= 1.0.3 =
* Added memory expiry (ISO 8601 expiry field, hourly cron cleanup).
* Added audit log with CSV export.

= 1.0.2 =
* Added Soul — a persistent per-user note your AI reads at session start.
* Added rate limiting (per-minute read/write limits).

= 1.0.1 =
* Added CORS origin allowlist setting.
* Added Connection Test button to Settings page.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.5 =
Dependency upgrades only — no database or configuration changes required.
