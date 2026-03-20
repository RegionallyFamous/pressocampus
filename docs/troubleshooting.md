# Troubleshooting

---

## Connection issues

### "My AI can't find the MCP server"

**Check the URL.** The Brain Endpoint URL is:
```
https://yoursite.com/brain
```
Make sure you're using `https://`, not `http://`. The original `wp-json` path also works as a fallback: `https://yoursite.com/wp-json/pressocampus/v1/mcp`.

**Check the Test Connection button.** In `Pressocampus → Settings → Connect`, click **Test Connection**. This fires a real request and shows the exact error if one occurs.

**Check permalink settings.** Go to `Settings → Permalinks` and click **Save Changes** to flush rewrite rules. Plain permalinks are not supported.

**Check the REST API.** Visit `https://yoursite.com/wp-json/` in a browser. If you see a JSON response, the REST API is working. If you see a 404, a plugin or server rule is blocking it.

---

### "I see a 401 Unauthorized"

Your OAuth token has expired or been revoked.

**If using Claude Desktop or Cursor:** The client should automatically refresh the token. If it doesn't, remove and re-add the server configuration to trigger re-authorization.

**Check the Connected Apps list.** Go to `Pressocampus → Settings → Advanced → Connected Apps`. If the client appears as "Revoked", you'll need to re-authorize from scratch.

**Check token expiry.** Admin notices appear when tokens are about to expire. If you missed one, just re-authorize.

---

### "I see a 404 on the authorization endpoint"

The OAuth authorization endpoint is:
```
https://yoursite.com/wp-json/pressocampus/v1/oauth/authorize
```

If this returns 404, flush your rewrite rules: `Settings → Permalinks → Save Changes`.

Also check if a security plugin (like Wordfence) is blocking REST API routes.

---

### "The consent screen is blank or unstyled"

This can happen on some heavily customized WordPress themes. The consent screen is served outside the normal theme context. Try visiting the URL directly:

```
https://yoursite.com/wp-json/pressocampus/v1/oauth/authorize?...
```

If the page loads without styling, the function is working — the visual issue won't affect the OAuth flow.

---

### "My AI client keeps asking me to re-authorize"

This usually means refresh token rotation is failing. Check:

1. **Object caching conflicts** — if you're using a persistent object cache that's aggressively expiring entries, rate limit counters may be resetting. Try flushing the cache: `wp pressocampus flush-cache`

2. **Database issues** — if the `wp_pressocampus_oauth_tokens` table is missing or corrupt, tokens can't be stored. Run `wp db check` to diagnose.

3. **Clock skew** — OAuth tokens use timestamps. If your server clock is off by more than a minute, token validation will fail. Ensure your server is running NTP.

---

## Memory issues

### "My AI isn't remembering things"

**Check the History log.** Go to `Pressocampus → History`. If `remember` calls appear in the log, memories are being stored but something is preventing retrieval. If no `remember` calls appear, the AI isn't calling the tool.

**Prompt your AI explicitly.** After connecting, say: "Please remember that I prefer concise answers." If the AI says "I've stored that," check History for the entry.

**Check your memory limit.** If you're at the limit (default: 1,000 memories), new `remember` calls will fail. Check `Settings → Advanced → Memory limit` and your current count via `wp pressocampus stats`.

---

### "A memory I stored is missing"

**Check if it was forgotten.** Look in History for a `forget` action around the time you stored the memory.

**Check if it expired.** If the memory had a TTL, it may have expired. Expired memories get the `pressocampus_expired` status. Check via WP-CLI:

```bash
wp post list --post_type=pressocampus_resource --post_status=pressocampus_expired
```

---

### "The search isn't finding something I know is stored"

Search runs against the resource index table (`wp_pressocampus_resource_index`). If a memory was added before the index was built, or if the index is stale, searches may miss it.

Flush and rebuild:

```bash
wp pressocampus flush-cache
```

Also verify the memory exists:

```bash
wp pressocampus list | grep -i "search term"
```

---

## Soul issues

### "My AI created a soul but it's full of placeholders"

This is expected on first connection. Your AI should walk you through filling them in. If it didn't, start a new conversation and say:

> "Please read my soul and help me fill in the placeholder values."

Your AI will read the soul, see the `[placeholder]` values, and ask you questions to fill them in.

---

### "My soul is gone"

The soul cannot be deleted via the MCP tools — it's protected. However, it can be deleted via WP-CLI or the database directly.

If your soul is missing:
1. Check WP-CLI: `wp pressocampus get pressocampus://yoursite.com/soul`
2. If it returns "not found", it was deleted manually or something went wrong during migration
3. Connect your AI and it will create a new soul from the template

If you exported your brain before losing the soul, you can import it:

```bash
wp pressocampus import --file=brain.json --replace
```

---

### "My soul says 'Status: empty'"

This is the machine-readable signal that tells your AI the soul hasn't been properly filled in yet. Your AI will see this and offer to help you fill it in.

To remove it manually, ask your AI: "Please update my soul and remove the Status: empty line."

---

## Performance issues

### "Responses are slow"

**Enable object caching.** If you're on a host that supports Redis or Memcached, enable it. Pressocampus uses WordPress object caching for rate limiting and frequent lookups — this has a significant impact on performance.

**Check database indexes.** The resource index table should have indexes on `uri` and `user_id`. Run `wp db check` to verify table integrity.

**Check your memory count.** At very high memory counts (5,000+), `resources/list` can be slow. Use memory groups and the `limit` parameter in `search_memory` to reduce the result set.

---

### "The resources/list response is huge"

This is expected if you have a lot of memories. Your AI client should page through results or use `search_memory` for specific lookups rather than loading everything at once.

If token limits are a concern, reduce the memory limit setting and archive old memories via export.

---

## Database issues

### "Plugin activation failed — database error"

This usually means MySQL doesn't have permission to create tables, or the table prefix is unusual.

Check:
```bash
wp db check
wp db query "SHOW TABLES LIKE 'wp_pressocampus%';"
```

If no Pressocampus tables exist, try reactivating:
```bash
wp plugin deactivate pressocampus && wp plugin activate pressocampus
```

---

### "I see errors about missing tables"

If the Pressocampus tables were deleted manually, reactivate the plugin to recreate them:

```bash
wp plugin deactivate pressocampus && wp plugin activate pressocampus
```

This runs the full installation routine including `dbDelta()`.

---

## Plugin conflicts

### "After activating Pressocampus, REST API requests fail"

Some security plugins (Wordfence, iThemes Security, All-In-One WP Security) can block REST API routes. Check:

1. Temporarily disable other plugins and test
2. Check your security plugin's REST API settings — add `pressocampus/v1/*` to its allowlist

### "OAuth redirects are being blocked"

Some caching plugins intercept all requests including OAuth redirects. Add the OAuth endpoints to your caching plugin's exclusion list:

```
/wp-json/pressocampus/v1/oauth/*
```

---

## Debugging

### Enable debug logging

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Pressocampus logs errors to `wp-content/debug.log`.

### Inspect the raw MCP response

```bash
curl -s -X POST https://yoursite.com/wp-json/pressocampus/v1/mcp \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{"protocolVersion":"2025-03-26","clientInfo":{"name":"debug","version":"1.0"},"capabilities":{}},"id":1}' | jq .
```

### Check discovery endpoints

```bash
# MCP discovery
curl -s https://yoursite.com/.well-known/mcp.json | jq .

# OAuth metadata
curl -s https://yoursite.com/.well-known/oauth-authorization-server | jq .
```

If these return 404, flush rewrite rules: `wp rewrite flush`

---

## Getting help

If you can't resolve an issue:

1. Search [existing issues](https://github.com/pressocampus/pressocampus/issues) — someone may have had the same problem
2. Open a new issue with:
   - WordPress version (`wp core version`)
   - PHP version (`php -v`)
   - Plugin version
   - Exact error message
   - Relevant debug log entries
   - What you've already tried
