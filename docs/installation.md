# Installation

> **Quick version:** Upload the zip, activate the plugin, paste your Brain Endpoint URL into your AI client. That's it.

---

## Requirements

| Requirement | Minimum | Notes |
|------------|---------|-------|
| WordPress | 6.4 | Earlier versions are untested |
| PHP | 8.3 | Typed properties, `json_validate()`, `sodium` extension |
| PHP extensions | `openssl`, `sodium` | Both are standard on any modern host |
| HTTPS | Required | OAuth 2.1 requires a secure origin |
| MCP client | MCP 2025-11-25 | Claude Desktop, Cursor, or any compliant client |

### Checking your PHP version

In your WordPress admin: `Tools → Site Health → Info → Server`. Your PHP version appears under "PHP".

From the command line:
```bash
php -v
```

### Does your host support this?

Pressocampus works on any host that runs WordPress. If you can run WooCommerce, you can run Pressocampus. This includes:

- **Shared hosting** — SiteGround, Bluehost, DreamHost, Kinsta, WP Engine
- **VPS / cloud** — DigitalOcean, Linode, AWS Lightsail, Hetzner
- **Managed WordPress** — Pressable, Flywheel, Cloudways
- **Self-hosted** — Any server with Apache/Nginx, PHP 8.3+, and MySQL 8+

The one hard requirement is **HTTPS**. If your site doesn't have an SSL certificate, your host can add one for free via Let's Encrypt in minutes.

---

## Installing the plugin

### Option 1 — WordPress admin (recommended for most people)

1. Go to [Releases](https://github.com/RegionallyFamous/pressocampus/releases) and download `pressocampus-{version}.zip`
2. In your WordPress admin: `Plugins → Add New → Upload Plugin`
3. Choose the zip file and click **Install Now**
4. Click **Activate Plugin**

You'll be automatically redirected to the Pressocampus Settings page.

### Option 2 — WP-CLI

```bash
# Install and activate from the latest GitHub release
wp plugin install https://github.com/RegionallyFamous/pressocampus/releases/latest/download/pressocampus.zip --activate

# Verify
wp plugin list --name=pressocampus
```

### Option 3 — Manual FTP/SFTP

1. Download and unzip `pressocampus-{version}.zip`
2. Upload the `pressocampus/` folder to `wp-content/plugins/`
3. Go to `Plugins` in your WordPress admin and activate **Pressocampus**

### Option 4 — Composer (for developers)

```bash
composer require pressocampus/pressocampus
```

Then activate via WP-CLI or the admin.

---

## What happens on activation

When Pressocampus activates, it automatically:

1. **Creates database tables** — four tables for OAuth clients/tokens, the memory index, and the audit log. These are created safely using `dbDelta()` and won't conflict with anything else.

2. **Generates an RSA key pair** — used to sign and verify OAuth tokens. Stored encrypted in `wp_options`. Never transmitted.

3. **Creates a service user** — a WordPress user named `pressocampus_service` with a minimal `pressocampus_agent` role. This user owns no memories and exists only for internal plugin operations.

4. **Registers rewrite rules** — for the `/.well-known/mcp.json` and `/.well-known/oauth-authorization-server` discovery endpoints.

5. **Redirects you to Settings** — so you can copy your Brain Endpoint URL and connect your first AI.

None of this requires any configuration. It all happens silently.

---

## First-time setup

After activation, you'll see the **Settings → Connect** page.

The only thing on this page you need is your **Brain Endpoint URL**:

```
https://yoursite.com/wp-json/pressocampus/v1/mcp
```

Copy this. You'll paste it into your AI client. That's the entire setup.

See [Connecting Your AI →](connecting-your-ai.md) for step-by-step instructions for Claude Desktop, Cursor, and other clients.

---

## Updating the plugin

Updates work through the normal WordPress plugin update mechanism. When a new version is available, you'll see a notification in `Plugins`.

Pressocampus uses database versioning — schema changes are applied automatically on update using `dbDelta()`, so you never need to do anything manually.

To update via WP-CLI:
```bash
wp plugin update pressocampus
```

---

## Uninstalling

To completely remove Pressocampus and all its data:

1. Go to `Pressocampus → Settings → Advanced`
2. Under **Uninstall Options**, check **Delete all data when uninstalling**
3. Go to `Plugins`, deactivate Pressocampus, then click **Delete**

This removes:
- All memories and the soul
- All OAuth clients and tokens
- All audit log entries
- All custom database tables
- All plugin options
- The `pressocampus_service` user and `pressocampus_agent` role

If you don't check the box, deactivating and deleting the plugin leaves your data intact — useful if you're temporarily disabling the plugin.

> **Before uninstalling:** Export your brain from `Settings → Advanced → Download Brain`. Your Soul and memories are irreplaceable.

---

## Server configuration notes

### Permalink structure

Pressocampus requires pretty permalinks. Go to `Settings → Permalinks` and choose any option other than **Plain**.

If you see 404 errors on the MCP endpoint, try visiting `Settings → Permalinks` and clicking **Save Changes** to flush rewrite rules.

### PHP memory limit

The default WordPress PHP memory limit (64M) is sufficient for most operations. If you're importing large brain exports, you may want 128M or higher.

```ini
; In php.ini or wp-config.php
memory_limit = 128M
```

### Cron jobs

Pressocampus uses WordPress cron for two background tasks:
- **Token expiry notifications** — runs daily
- **Memory expiration** — runs hourly (for memories with TTL set)

If your host has disabled WordPress cron (`DISABLE_WP_CRON = true`), configure a real cron job:

```bash
# Add to crontab
*/5 * * * * wget -q -O - https://yoursite.com/wp-cron.php?doing_wp_cron >/dev/null 2>&1
```

A notice appears in the Pressocampus settings if `DISABLE_WP_CRON` is detected.

### Object caching

Pressocampus uses WordPress object caching for rate limiting and frequent lookups. If you're running Redis or Memcached, it works automatically and significantly improves performance under load. No configuration required.
