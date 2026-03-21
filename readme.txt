=== Pressocampus ===
Contributors: regionallyfamous
Tags: ai, memory, mcp, claude, chatgpt
Requires at least: 6.4
Tested up to: 6.9
Stable tag: 1.0.25
Requires PHP: 8.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Your AI will finally remember you. Preferences, context, and history — stored on your site, yours forever.

== Description ==

Every AI conversation starts from zero. Your AI doesn't know your name, your preferences, the decisions you made last week, or the projects you're in the middle of. You spend the first few minutes re-explaining yourself. Every. Single. Time.

**Pressocampus fixes that.**

Install Pressocampus on any WordPress site and your AI will start building a permanent memory of you — your preferences, your context, the things that matter to you. The next time you open a conversation, your AI already knows who you are.

**What changes when you use Pressocampus:**

* Your AI remembers your preferences and applies them automatically — no more repeating yourself
* Decisions you make together get stored, so you never lose the reasoning behind them
* Personal context your AI has learned stays with you permanently, not just for one session
* When you switch AI tools, the memory comes with you — it's stored on your site, not inside any one app
* A "Soul" document gives every AI you connect a consistent understanding of who you are before the first message

**The Soul**

The Soul is a special document your AI reads at the very start of every session. It describes you — how you communicate, what you're working on, what matters to you, how you think. You build it together with your AI over time. When you connect a new AI tool, it reads the same Soul and immediately understands you.

**Your memory, your data**

Everything lives on your WordPress site. Not in OpenAI's servers. Not in Anthropic's cloud. Yours. You can export it any time, move it to a new site, or delete it completely — and when you uninstall the plugin, nothing is left behind.

**Works with the AI tools you already use**

* Claude (Claude.ai and Claude Desktop)
* Cursor
* Any AI client that supports the open MCP standard

**For the curious: how it works**

Pressocampus implements the open Model Context Protocol (MCP) standard, which lets AI assistants read and write structured data through a secure endpoint on your site. Your AI connects once using a standard browser-based authorization flow (you click "Allow") and then has permanent access to your memory store.

== Installation ==

1. Upload the plugin ZIP to **Plugins → Add New → Upload Plugin** and activate it.
2. Go to **Pressocampus → Settings** and copy your Brain URL.
3. Paste that URL into your AI client's settings. (The settings page has copy-paste snippets for Claude, Cursor, and others.)
4. Start a new conversation. Your AI will ask you to authorize it the first time — click Allow.

That's it. Your AI will start building your memory automatically from that point forward.

**Requirements:** PHP 8.3+ and WordPress 6.4+. The `openssl` PHP extension must be active (it is by default on most hosts).

== Frequently Asked Questions ==

= Does this send my data anywhere? =

No. Pressocampus stores everything in your own WordPress database and never sends your data to any third-party service. Your AI connects directly to your site — there is no Pressocampus server in the middle.

= Which AI tools work with this? =

Claude Desktop, Claude.ai (via MCP), and Cursor work natively. Any AI tool that supports the open MCP standard over HTTP will also work. More tools are adding MCP support regularly.

= What does my AI actually remember? =

Your AI decides what's worth storing — that's the whole point. It's designed to remember preferences ("I prefer short answers"), decisions ("We chose Postgres because of JSONB support"), personal context ("I have a presentation Friday"), and working style. It won't remember casual greetings or one-off questions.

= Can I see what my AI has stored? =

Yes. The **History** page in your WordPress admin shows every memory your AI has saved, updated, or deleted, along with the reason it gave. You can search, filter, and export the full log.

= What happens to my data if I uninstall the plugin? =

Deleting the plugin removes everything — all stored memories, all settings, all database tables. Nothing is left behind.

= Is my memory accessible to other people on my WordPress site? =

No. On a multi-user WordPress site, every person's memory store is completely private. An AI connected as you can only ever see your memories.

= Can I connect more than one AI tool? =

Yes. Connect as many AI clients as you want — they all share the same memory store and Soul. If two clients are active at the same time, the plugin handles conflicts automatically so they can't accidentally overwrite each other.

= What is the Soul? =

The Soul is a special document — a bit like a letter you've written to every AI you'll ever use. It describes who you are, how you like to communicate, and what matters to you. Every AI reads it at the start of every session. You build it with your AI through normal conversation, and it gets better over time.

= Do I need a specific hosting setup? =

Any host running PHP 8.3+ with pretty permalinks enabled will work. The plugin checks your setup automatically and tells you if anything needs attention. If you're on nginx (not Apache), no `.htaccess` changes are needed.

== Changelog ==

= 1.0.25 =
* Security: OAuth client IDs now use a cryptographically secure random generator instead of `uniqid()`.
* Security: New OAuth client secrets are hashed with bcrypt; existing plaintext secrets continue to work.
* Security: The `initialize` handshake is now rate-limited, preventing repeated reconnections from bypassing throttling.
* Security: Fixed rate-limit bypass where an authenticated session without a `token_id` could skip all throttling.
* Fixed: `tool_forget` now verifies `wp_delete_post()` succeeded before touching back-references or the index row.
* Fixed: `update_soul` / `update_soul_section` return a clean tool error on soul-lock race instead of a 500.
* Performance: `rebuild_index` uses batched direct SQL instead of `WP_Query(posts_per_page=-1)`.
* Performance: `get_user_groups` uses a single JOIN query — removes potential 1 000-item `IN` clause.
* Performance: `rewrite_related_uri` primes postmeta cache before the update loop (eliminates N+1 queries).
* Reliability: Soul creation and index rebuild use MySQL `GET_LOCK` to prevent race conditions.
* Code quality: Rate-limit error messages centralised; fixes hardcoded limit value in `search_memory`.

= 1.0.24 =
* Updated to MCP spec 2025-11-25: version negotiation, `MCP-Protocol-Version` header validation, `Origin` → 403 DNS-rebinding protection, `GET /brain` → 405, `isError: false` on tool results.
* Custom brain icon in the WordPress admin menu.
* Admin menu item moved to the bottom of the sidebar.

= 1.0.22 =
* First-install Quick Start card: three-step onboarding panel with Brain URL, copy-paste config snippets for Claude Desktop / Cursor / generic MCP, and an inline Test Connection button.
* Fixed HTTP status propagation: ETag conflict now returns 409 at the transport level.
* Fixed duplicate detection: `possible_related` (≥ 40% similarity) replaces incorrect `possible_duplicate`; `possible_contradiction` threshold raised to 70%.
* Soul update emails are now synchronous.
* Added `expires_at` support to the `remember` tool.
* `get_user_groups()` result is now cached in the object cache for 5 minutes.
* Access token TTL raised from 1 hour to 8 hours.

= 1.0.22 =
* Fixed: /brain rewrite rule missing after deactivate → reactivate. The activation hook was calling flush_rewrite_rules() before the init hook had a chance to register the /brain rule, so the compiled rewrite table went to the database without it. The fix uses a transient flag so the flush happens on the next init call, after the rule is registered.

= 1.0.21 =
* Added phpcs.xml to align local PHPCS with WordPress-Extra standard; globally excluded unavoidable patterns (custom-table direct queries, schema migrations, dynamic IN-list placeholders, meta_query/tax_query). No functional changes.

= 1.0.20 =
* Documentation overhaul — rewritten readme for clarity; all docs updated to reflect tools added in v1.0.18 and v1.0.19.

= 1.0.19 =
* Code quality pass — resolved all WordPress Plugin Check errors and warnings; no functional changes.

= 1.0.18 =
* Added `list_memories` tool — browse your memory store by group, sort order, and page.
* Added `tag_memory` tool — change a memory's group or priority.
* Added Session Briefing resource — a dynamic snapshot your AI reads at session start showing critical memories, recent activity, and memories that may need a refresh.
* Added inline related content — when reading a memory, related memories now include their excerpts so your AI doesn't need extra round-trips.

= 1.0.17 =
* Raised soul snapshot limit from 2 KB to 6 KB so more of your soul is available at session start.
* Added automatic instruction to fetch the full soul when it exceeds the snapshot limit.
* Tightened tool descriptions to encourage better AI behavior (search before storing, always provide context, etc.).
* Removed the manual starter prompt — the server-side onboarding now handles first-session setup reliably.

= 1.0.16 =
* Made the `initialize` response more directive when no soul exists — the AI now receives explicit step-by-step instructions to conduct the soul interview before doing anything else.

= 1.0.15 =
* Fixed OAuth authorization failing on sites where a security plugin blocks the WordPress REST API — OAuth endpoints now run through a dedicated `/brain/oauth/` path that bypasses REST API middleware entirely.
* Added automatic pretty-permalink detection and correction on plugin boot.
* Added detailed diagnostics page to help identify connection problems.

= 1.0.14 =
* Added Diagnostics tab with live checks for every component in the connection chain.
* Added automatic RSA key regeneration on plugin boot if keys are missing.

= 1.0.13 =
* Fixed fatal error on activation caused by missing OpenSSL extension — now shows a clear admin notice instead of crashing.

= 1.0.12 =
* Added soul truncation notice — when the soul is larger than the session snapshot limit, the AI is told to fetch the full document.

= 1.0.11 =
* Fixed WWW-Authenticate header missing from unauthenticated `/brain` responses, which prevented some OAuth clients from discovering the auth server.

= 1.0.5 =
* Updated OAuth library to v9.3 for stricter OAuth 2.1 compliance.
* Added GitHub Actions release workflow.

= 1.0.4 =
* Added full-text search index and composite indexes to the memory store for faster search.

= 1.0.3 =
* Added memory expiry — memories can have an optional expiry date after which they disappear from search and listings.
* Added audit log with CSV export.

= 1.0.2 =
* Added the Soul.
* Added per-minute rate limiting on reads and writes.

= 1.0.1 =
* Added CORS allowlist setting.
* Added Connection Test button.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.25 =
Security hardening, performance improvements, and reliability fixes. Existing OAuth clients continue to work. No database changes required — update and go.

= 1.0.24 =
MCP spec 2025-11-25 upgrade and admin UI improvements. No database changes required — update and go.

= 1.0.22 =
Adds first-install onboarding card, fixes duplicate detection and HTTP status codes. No database changes required.

= 1.0.20 =
Documentation update only — no database or configuration changes required.

= 1.0.19 =
Code quality pass — no functional changes, no database changes required.

= 1.0.18 =
Adds list_memories, tag_memory, Session Briefing, and inline related content. No database changes required — update and go.
