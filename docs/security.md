# Security

Pressocampus handles personal data — your thoughts, preferences, and decisions. Security is not an afterthought.

---

## Authentication model

Pressocampus uses **OAuth 2.1 with PKCE** as its sole authentication method. There are no API keys, application passwords, or unauthenticated endpoints.

### Why OAuth 2.1?

- **Standard** — the same protocol used by banks, Google, and GitHub. Every modern AI client knows how to use it.
- **No credentials in config files** — you never put your WordPress password anywhere near your AI client.
- **Granular revocation** — you can disconnect a specific client without affecting others or changing your password.
- **Short-lived access tokens** — tokens expire; refresh tokens are rotated on use.

### Why PKCE?

PKCE (Proof Key for Code Exchange) prevents authorization code interception attacks. Even if an attacker intercepts the authorization code during the OAuth flow, they cannot exchange it for tokens without the code verifier that only the legitimate client has.

This is especially important for AI clients that run locally, where redirect URIs are less controllable.

---

## OAuth flow

```
AI Client                           Pressocampus                    You
    │                                     │                            │
    │── POST /oauth/register ────────────>│                            │
    │<─ client_id, client_secret ─────────│                            │
    │                                     │                            │
    │── GET /oauth/authorize ─────────────>│                           │
    │                                     │── Consent screen ─────────>│
    │                                     │<─ "Allow" ─────────────────│
    │<─ authorization_code ───────────────│                            │
    │                                     │                            │
    │── POST /oauth/token ────────────────>│                           │
    │<─ access_token, refresh_token ──────│                            │
    │                                     │                            │
    │── POST /mcp (Bearer token) ─────────>│                           │
    │<─ MCP response ─────────────────────│                            │
```

### Dynamic client registration

AI clients register themselves automatically via `POST /pressocampus/v1/oauth/register`. No manual app registration required.

### Access tokens

- JWT format, RSA-signed
- Expire after **1 hour** (configurable)
- Include the WordPress user ID as the subject

### Refresh tokens

- Opaque, stored hashed in the database
- **Rotated on every use** — the old refresh token is immediately invalidated when a new one is issued
- Expire after **30 days** of inactivity

### Consent screen

The consent screen tells users exactly what access is being granted:

> **[Client Name]** is requesting permission to **read, write, and delete** memories on your Pressocampus brain.

There is a single scope: `pressocampus:memory`. There is no read-only mode — an authorized client has full access to your memories.

---

## RSA key pair

On activation, Pressocampus generates a 2048-bit RSA key pair:

- **Private key** — stored encrypted using `sodium_crypto_secretbox` in `wp_options`. Never exposed.
- **Public key** — stored in plaintext, exposed in the OAuth authorization server metadata. Used by clients to verify token signatures.

The key pair is regenerated if the private key is ever deleted. You can see the public key fingerprint in `Settings → Advanced`.

**Included in exports.** When you export your brain, the public key is included so the export can be cryptographically verified. The private key is never exported.

**Re-encrypted on import.** If you import a brain.json from another site, the key material is re-encrypted under your current installation's encryption key.

---

## Per-user data isolation

Every memory is owned by a specific WordPress user (`post_author`). The OAuth token identifies which user is authenticated, and all queries are automatically scoped to that user.

It is not possible for an AI client authorized as User A to read, write, or delete User B's memories. This is enforced at the database query level, not just at the API level.

---

## Rate limiting

To prevent abuse and accidental runaway automation:

| Operation | Limit |
|-----------|-------|
| Read operations | 60 per minute per user |
| Write operations | 30 per minute per user |

When a limit is exceeded, the response is HTTP 429 with a `Retry-After` header.

Limits are tracked using WordPress object caching (falls back to transients if no object cache is present).

---

## CORS policy

Pressocampus uses credentialed CORS with Origin reflection on the MCP endpoint. This means:

- Requests from any origin are accepted
- The response includes `Access-Control-Allow-Origin: [requesting origin]`
- Credentials are allowed (`Access-Control-Allow-Credentials: true`)

You can restrict this to specific origins in `Settings → Advanced → CORS`.

---

## HTTPS requirement

OAuth 2.1 requires HTTPS. Pressocampus will not issue tokens over HTTP in production. The consent screen redirects to HTTPS if an HTTP request is received.

In local development environments (localhost), HTTP is allowed for testing.

---

## Input sanitization

All memory content is sanitized before storage:

- Content is stored raw (unsanitized) and escaped on output — following WordPress best practices
- MIME type is validated against an allowlist
- URI uniqueness is enforced at the database level
- Content size is validated against the configured maximum

---

## Threat model

### What Pressocampus protects against

- **Unauthorized access** — no token, no access
- **Cross-user data access** — per-user query scoping
- **Token interception** — PKCE in the authorization flow
- **Concurrent write conflicts** — ETag-based optimistic locking
- **Runaway AI writes** — rate limiting
- **Accidental deletion** — soul/index are protected, deletion requires explicit context
- **Mass deletion detection** — admin notice when 10+ memories are deleted in a session

### What Pressocampus does not protect against

- **A compromised WordPress admin account** — if someone has admin access to your WordPress site, they have access to your memories. Secure your WordPress admin.
- **A compromised AI client** — if your AI client is compromised, the attacker has your OAuth tokens. Revoke from `Settings → Advanced → Connected Apps`.
- **The WordPress host** — your memories live on your server. Secure your server.
- **An AI that goes rogue** — Pressocampus logs everything in History. Review it periodically.

---

## Responsible disclosure

If you find a security vulnerability in Pressocampus, please report it privately by opening a [GitHub Security Advisory](https://github.com/RegionallyFamous/pressocampus/security/advisories/new) rather than a public issue.
