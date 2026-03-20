# Admin Guide

The Pressocampus admin interface is intentionally minimal. WordPress is the backend — your AI is the interface. The admin exists for three things: connecting clients, reviewing history, and managing settings.

---

## Menu structure

After activation, you'll find **Pressocampus** in your WordPress admin sidebar:

```
Pressocampus
├── History
└── Settings
    ├── Connect
    └── Advanced
```

---

## Settings → Connect

This is your home base. Everything you need to get an AI client connected.

### Brain Endpoint URL

```
https://yoursite.com/wp-json/pressocampus/v1/mcp
```

This is the URL you paste into your AI client. Copy it with the one-click copy button.

### Share Brain

A dropdown with pre-filled configuration snippets for popular AI clients. Selecting a client shows a JSON block you can copy directly into that client's config file.

Available snippets:
- **Claude Desktop** — `claude_desktop_config.json` format
- **Cursor** — `~/.cursor/mcp.json` format
- **Generic MCP** — standard HTTP transport format

### Starter Prompt

A copy button that gives you a natural-language prompt to paste into your first conversation with a newly connected AI. This prompt instructs the AI to read your soul, introduce itself, and begin the onboarding flow.

### Soul status

A one-line indicator showing:
- `Soul exists` — your soul has been created and has content
- `Soul is empty` — your soul was created from the template but hasn't been filled in yet
- `No soul yet` — no AI has connected and created a soul yet

### Test Connection

A button that fires a test `initialize` request to your MCP endpoint and shows the result — either a green "Connected" status or an error with diagnostics.

---

## History

The History page is an audit log of everything your connected AIs have done. Every `remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, and `search_memory` call is logged.

The log is displayed using WordPress DataViews — it's searchable, sortable, and filterable.

### Columns

| Column | Description |
|--------|-------------|
| **Action** | What happened: Remembered, Forgot, Updated, Searched, etc. |
| **Memory** | The memory affected (linked to its URI) |
| **Agent** | The name of the OAuth client that performed the action |
| **Context** | The optional note the AI provided explaining why |
| **Date** | When it happened |

### Filtering

- Filter by **action type** to see all remembers, or all deletes
- Filter by **agent name** to see what a specific AI client has been doing
- Filter by **date range** to review a specific period
- Use the **search box** to find entries by content or context

### What gets logged

Everything. Every read, write, and delete is recorded with the agent name, timestamp, and context. You always know exactly what your AI has been doing.

### What doesn't get logged

The *content* of your memories is not duplicated into the audit log — only the action, URI, and context. Your memories live in one place: the memory store itself.

---

## Settings → Advanced

### Connected Apps

A list of all OAuth clients that have ever been authorized, showing:
- Client name (as provided by the client during registration)
- Authorization date
- Last active
- Status (Active / Revoked)

Click **Revoke** to immediately invalidate a client's tokens. The client will need to re-authorize to reconnect.

### CORS settings

By default, Pressocampus accepts requests from any origin using credentialed CORS with Origin reflection. If you want to restrict which origins can connect, you can enter an allowlist here.

Most users should leave this at the default. You only need to change it if you're running a tightly controlled environment.

### Rate limits

Override the default rate limits:

| Setting | Default | Description |
|---------|---------|-------------|
| Read limit | 60 / min | `resources/list`, `resources/read`, `search_memory` |
| Write limit | 30 / min | `remember`, `forget`, `update_*` |

### Memory limit

The maximum number of memories per user. Default: **1,000**.

When this limit is reached, `remember` will fail with a `memory_limit_exceeded` error. Your AI will inform you and suggest consolidating or archiving old memories.

### Max memory size

The maximum content size for a single memory. Default: **64KB**.

### Download Brain

Downloads a ZIP file containing:
- `SOUL.md` — your soul as a plain Markdown file
- `INDEX.md` — your index
- `memories/` — one `.md` file per memory, named by their slug
- `brain.json` — a machine-readable manifest with full metadata for every memory

This export is fully portable. It can be imported on any Pressocampus installation.

### Import

Upload a `brain.json` or a folder of `.md` files exported from Pressocampus (or a compatible format). Memories are merged by URI — existing memories are not overwritten unless you check **Replace existing**.

### DISABLE_WP_CRON notice

If your site has `DISABLE_WP_CRON` set to `true`, a notice here explains how to configure a real cron job to ensure TTL expiry and token expiry notifications work correctly.

### Uninstall options

- **Delete all data when uninstalling** — when checked, deleting the plugin removes all memories, OAuth data, audit logs, and plugin settings. Unchecked: data persists.

---

## Admin notices

Pressocampus shows in-admin notices for events that need your attention:

| Notice | What it means |
|--------|---------------|
| `Your soul needs attention` | The soul was created but still has `[placeholder]` values |
| `Client token expiring soon` | An authorized app's access will expire in the next 7 days |
| `Mass delete detected` | An AI client deleted 10+ memories in one session |
| `Soul was updated` | Your AI updated your soul (shown once per soul change) |
| `Migration needed` | Your site's domain changed and URIs may be stale |

---

## Multi-user sites

On a WordPress site with multiple users:

- Each user's History page shows only their own activity
- Administrators see a user selector to review any user's history
- Connected Apps shows only apps connected under the current user
- The "Download Brain" export is per-user

Users with the `pressocampus_agent` role (i.e., the `pressocampus_service` system user) can interact with the plugin internally but have no access to the admin UI.
