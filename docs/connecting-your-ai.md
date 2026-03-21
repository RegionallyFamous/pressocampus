# Connecting Your AI

Pressocampus works with any AI client that supports **MCP 2025-11-25** over HTTP. This page covers the most common clients.

Your Brain Endpoint URL is on `Pressocampus → Settings → Connect`. It looks like:

```
https://yoursite.com/brain
```

---

## Claude Desktop

Claude Desktop supports MCP natively. This is the easiest and most capable connection.

### Step 1 — Find your config file

| Platform | Location |
|----------|----------|
| macOS | `~/Library/Application Support/Claude/claude_desktop_config.json` |
| Windows | `%APPDATA%\Claude\claude_desktop_config.json` |

### Step 2 — Add your Brain Endpoint

Open the config file (create it if it doesn't exist) and add your server:

```json
{
  "mcpServers": {
    "pressocampus": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://yoursite.com/brain"]
    }
  }
}
```

Claude Desktop uses `npx mcp-remote` as a proxy to connect to HTTP MCP servers. You can use any name in place of `"pressocampus"` — it's just a label.

### Step 3 — Restart Claude Desktop

Quit and reopen Claude Desktop. You'll see a small plug icon appear in the input area when the MCP connection is active.

### Step 4 — Authorize

The first time you send a message, Claude will ask you to authorize. Click the link, log into your WordPress site, and click **Allow**. Claude stores the authorization and never asks again.

### Step 5 — First conversation

Start a new conversation. Claude will automatically read your soul from the connection handshake and introduce itself based on what it finds. If no soul exists yet, it will walk you through a brief interview to create one.

---

## Cursor

Cursor supports MCP in its settings.

### Via settings UI

1. Open Cursor → **Settings** → **Features** → **MCP Servers**
2. Click **Add New MCP Server**
3. Set:
   - **Name:** `pressocampus` (or anything you like)
   - **Type:** `HTTP`
   - **URL:** `https://yoursite.com/brain`
4. Click **Save**

### Via `~/.cursor/mcp.json`

```json
{
  "mcpServers": {
    "pressocampus": {
      "type": "http",
      "url": "https://yoursite.com/brain"
    }
  }
}
```

Restart Cursor. Authorize when prompted.

---

## Other MCP clients

Any client implementing MCP 2025-11-25 with Streamable HTTP transport will work. The configuration is typically:

| Field | Value |
|-------|-------|
| Transport | `HTTP` / `Streamable HTTP` |
| URL | `https://yoursite.com/brain` |
| Auth | `OAuth 2.1` (auto-discovered via `/.well-known`) |

**Discovery endpoints** — clients that support auto-discovery can find everything at:
- `https://yoursite.com/.well-known/mcp.json` — MCP server metadata
- `https://yoursite.com/.well-known/oauth-authorization-server` — OAuth metadata

---

## The authorization flow

The first time any AI client connects, it goes through OAuth 2.1 with PKCE:

1. **Client registers** — your AI client registers itself via dynamic client registration. This is automatic.
2. **You authorize** — a browser window opens to your WordPress site showing a consent screen. You log in (if not already) and click **Allow**.
3. **Client stores tokens** — your AI client receives an access token and refresh token. The refresh token keeps the connection alive without re-authorization.
4. **You're done** — subsequent connections are seamless.

You can see all connected apps and revoke access at any time in `Pressocampus → Settings → Advanced → Connected Apps`.

---

## Multi-user setups

On a WordPress site with multiple users:

- Each WordPress user has their own **separate** set of memories
- An AI authorized as "Alice" can only see Alice's memories
- An AI authorized as "Bob" can only see Bob's memories
- There is no cross-contamination

The Brain Endpoint URL is the same for everyone — what differs is which WordPress account each person authorizes with.

---

## Connecting multiple AI clients

You can connect as many AI clients as you want to the same Pressocampus installation. They all share the same memory store and soul for your user.

If two clients write to the same memory simultaneously, **ETag-based optimistic locking** prevents silent overwrites — the second write will receive a `409 Conflict` and the client will re-read before retrying.

---

## Revoking access

To disconnect an AI client:

1. Go to `Pressocampus → Settings → Advanced → Connected Apps`
2. Find the client you want to revoke
3. Click **Revoke**

The client's tokens are immediately invalidated. It will need to re-authorize to reconnect.
