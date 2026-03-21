# Admin Guide

The Pressocampus admin interface is intentionally minimal. WordPress is the backend — your AI is the interface. The admin exists for three things: connecting clients, reviewing history, and managing settings.

---

## Menu structure

After activation, you'll find **Pressocampus** in your WordPress admin sidebar:

```
Pressocampus
├── Settings
│   ├── Connect
│   └── Advanced
└── History
```

---

## Settings → Connect

This is your home base. Everything you need to get an AI client connected.

### Brain Endpoint URL

```
https://yoursite.com/brain
```

This is the URL you paste into your AI client. Copy it with the one-click copy button.

### Share Brain

A dropdown with pre-filled configuration snippets for popular AI clients. Selecting a client shows a JSON block you can copy directly into that client's config file.

Available snippets:
- **Claude Desktop** — uses `npx mcp-remote` as a proxy (standard Claude Desktop MCP format)
- **Cursor** — native `"type": "http"` format for `~/.cursor/mcp.json`
- **Generic MCP** — standard endpoint + auth format

### Soul status

A one-line indicator showing:
- `Soul not yet written` — the AI has not connected and written its soul yet
- `Soul is live` — the AI has written its soul and the relationship has begun

### Test Connection

A button that sends a `{"method":"ping"}` request to your MCP endpoint and shows whether the endpoint is reachable and responding. A 401 response also counts as "reachable" — it means OAuth is working.

---

## History

The History page is an audit log of everything your connected AIs have done. Every `remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, and `search_memory` call is logged.

The log is a searchable table with URL-based filters.

### Columns

| Column | Description |
|--------|-------------|
| **Action** | What happened: Saved memory, Deleted memory, Updated memory, Updated soul, Updated soul section, Searched memories |
| **Memory** | The memory affected (linked to its URI) |
| **Agent** | The name of the OAuth client that performed the action |
| **Context** | The optional note the AI provided explaining why |
| **Date** | When it happened |

### Filtering

- Filter by **action type** to see all remembers, or all deletes
- Filter by **agent name** to see what a specific AI client has been doing
- Use the **search box** to find entries by memory name or context

### What gets logged

Everything. Every read, write, and delete is recorded with the agent name, timestamp, and context. You always know exactly what your AI has been doing.

### What doesn't get logged

The *content* of your memories is not duplicated into the audit log — only the action, URI, and context. Your memories live in one place: the memory store itself.

---

## Settings → Advanced

### Connected Apps

A list of all OAuth clients currently authorized, showing:
- App name (as provided by the client during registration)
- Connected (how long ago)
- Last used (how long ago)

Click **Revoke** to immediately invalidate and delete a client's tokens. The client will need to re-authorize to reconnect.

### CORS settings

By default, Pressocampus only accepts CORS requests from origins in your explicit allowlist or from the site's own origin. If you're connecting an AI client that runs in a browser context, add its origin here.

Most local AI clients (Claude Desktop, Cursor) don't send an `Origin` header, so they are unaffected by this setting. Leave this blank if you're not sure.

### Rate limits

Override the default rate limits:

| Setting | Default | Description |
|---------|---------|-------------|
| Read limit | 60 / min | `resources/list`, `resources/read`, `search_memory` |
| Write limit | 30 / min | `remember`, `forget`, `update_*` |

### Memory limit

The maximum number of memories per user. Default: **1,000**.

When this limit is reached, `remember` will fail with a `memory_limit_reached` error. Your AI will inform you and suggest archiving old memories.

### Max memory size

The maximum content size for a single memory. Default: **512 KB**.

### Download Brain

Downloads a file containing all your memories. If the server has the PHP `ZipArchive` extension, you get a `.zip` containing a single `pressocampus-brain.json` file. Otherwise the raw `.json` file is served directly.

This export is fully portable and can be re-imported via WP-CLI on any Pressocampus installation.

### DISABLE_WP_CRON notice

If your site has `DISABLE_WP_CRON` set to `true`, a notice here explains how to configure a real cron job to ensure TTL expiry, token expiry notifications, and audit log purging work correctly.

---

## Admin notices

Pressocampus shows in-admin notices for events that need your attention:

| Notice | What it means |
|--------|---------------|
| `Soul not yet written` | No AI has connected and written its soul yet — connect a client to start |
| `Client token expiring soon` | An authorized app's access will expire in the next 7 days |
| `Soul was updated` | Your AI updated its soul (shown once per soul change) |
| `Migration needed` | Your site's domain changed and URIs may be stale |

---

## Multi-user sites

On a WordPress site with multiple users:

- Each user's History page shows only their own activity
- Connected Apps shows only apps connected under the current user
- The "Download Brain" export is per-user

Users with the `pressocampus_agent` role (i.e., the `pressocampus_service` system user) can interact with the plugin internally but have no access to the admin UI.
