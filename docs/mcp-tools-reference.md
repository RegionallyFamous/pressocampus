# MCP Tools Reference

Pressocampus exposes 6 tools to connected AI clients via the MCP `tools/list` and `tools/call` methods. These are the only way memories are written, updated, or deleted — there is no UI for writing memories.

All tools require an authorized OAuth 2.1 connection. All write operations are rate-limited to **30 per minute**.

---

## `remember`

Store a new memory permanently.

**When to use:** When the user states a preference, shares a personal fact, describes a decision, or explicitly asks you to remember something. Do not call for questions, greetings, or casual conversation.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `content` | string | Yes | The content to store (plain text or Markdown) |
| `name` | string | No | Display name. Auto-generated from first 60 chars if omitted. |
| `group` | string | No | Category/group. Prefer existing groups from `initialize` meta. |
| `related` | string[] | No | URIs of related memories |
| `priority` | string | No | `critical`, `important`, `normal` (default), or `low` |
| `confidence` | string | No | `high`, `medium` (default), or `low` |
| `context` | string | No | Why you're storing this — appears in History. Max 200 chars. |

### Returns

```json
{
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "name": "Prefers TypeScript over JavaScript",
  "created": true
}
```

If a very similar memory already exists, `created` will be `false` and the existing memory will have been updated instead.

### Example

```
User: "I always want you to use TypeScript, never plain JavaScript."
AI: [calls remember]
  content: "Strongly prefers TypeScript over JavaScript for all projects."
  group: "technical"
  priority: "important"
  context: "User explicitly stated this preference"
```

---

## `forget`

Permanently delete a memory.

**When to use:** Only when the user explicitly asks to forget something. This is irreversible.

The Soul (`pressocampus://yoursite.com/soul`) and Index (`pressocampus://yoursite.com/index`) cannot be forgotten via this tool.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uri` | string | Yes | The URI of the memory to delete |
| `context` | string | No | Why you're deleting this — appears in History |

### Returns

```json
{
  "deleted": true,
  "uri": "pressocampus://yoursite.com/memory/abc12345"
}
```

### Errors

| Code | Meaning |
|------|---------|
| `not_found` | No memory with that URI exists (or you don't own it) |
| `protected_resource` | Attempted to forget the soul or index |
| `rate_limited` | Too many write operations — slow down |

### Example

```
User: "Forget that I prefer TypeScript — I've changed my mind."
AI: [calls forget]
  uri: "pressocampus://yoursite.com/memory/abc12345"
  context: "User changed preference"
```

---

## `update_memory`

Update the content of an existing memory.

**When to use:** When a stored fact has changed or needs correction. Do not use for the soul — use `update_soul` or `update_soul_section` instead.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uri` | string | Yes | The URI of the memory to update |
| `content` | string | Yes | The new content |
| `etag` | string | No | ETag from `resources/read` for optimistic concurrency |
| `context` | string | No | Why you're updating this — appears in History |

### Returns

```json
{
  "updated": true,
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "etag": "a1b2c3d4"
}
```

### Optimistic locking with ETags

If you provide an `etag` that doesn't match the current state of the memory, the update is rejected with a `409 Conflict`. This prevents one AI client from silently overwriting changes made by another client.

To use it:
1. Read the memory via `resources/read` — the response includes an `etag`
2. Pass that `etag` when calling `update_memory`
3. If the memory changed since you read it, you'll get a 409 and should re-read before retrying

### Errors

| Code | Meaning |
|------|---------|
| `not_found` | No memory with that URI |
| `conflict` | ETag mismatch — memory was updated since you last read it |
| `rate_limited` | Too many write operations |

---

## `update_soul`

Update or create the Soul — the user's persistent identity document.

**When to use:** Only for full restructuring of the soul. For targeted section updates, use `update_soul_section` instead. This creates the soul if it doesn't exist.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `content` | string | Yes | The full updated soul content (Markdown) |
| `etag` | string | No | ETag for optimistic concurrency |
| `context` | string | No | Why you're updating the soul |

### Returns

```json
{
  "updated": true,
  "uri": "pressocampus://yoursite.com/soul",
  "etag": "e5f6a7b8"
}
```

### Notes

- The soul is stored with up to **20 revisions** (other memories cap at 5)
- If the soul previously had `Status: empty`, that line is removed after the first real update
- WordPress admin receives an email notification if the soul is updated (configurable)

---

## `update_soul_section`

Update a single `## Section` of the soul. **This is the preferred method for soul updates.**

**When to use:** Whenever the AI learns something new about the user that belongs in the soul. Faster, safer, and less likely to accidentally overwrite other sections than `update_soul`.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `section` | string | Yes | The `## Heading` text, e.g. `"How I Communicate"` |
| `content` | string | Yes | New body for that section |
| `context` | string | No | Why you're updating this section |

### Returns

```json
{
  "updated": true,
  "section": "How I Communicate",
  "uri": "pressocampus://yoursite.com/soul"
}
```

### Notes

- If the specified section doesn't exist, it's added to the end of the soul
- Section matching is case-insensitive and trims whitespace
- The soul's ETag is automatically updated after a section change

### Example

```
User: "Actually, I hate bullet points. Just write in prose."
AI: [calls update_soul_section]
  section: "How I Communicate"
  content: "Write in clear prose, not bullet points. Lead with the answer,
  then explain. Short sentences. Active voice. Never pad responses with
  affirmations — just answer the question."
  context: "User stated preference against bullet points"
```

---

## `search_memory`

Search memories by keyword.

**When to use:** When the user asks a question that might be answered by stored memories. Call this before answering factual questions about the user's preferences, history, or decisions.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | Search terms |
| `group` | string | No | Limit search to a specific group |
| `limit` | integer | No | Maximum results to return (default: 10, max: 50) |
| `context` | string | No | Why you're searching |

### Returns

```json
[
  {
    "uri": "pressocampus://yoursite.com/memory/abc12345",
    "name": "Prefers TypeScript over JavaScript",
    "excerpt": "Strongly prefers TypeScript over JavaScript for all projects.",
    "confidence": "high",
    "updated_at": "2025-03-15T14:22:00Z"
  }
]
```

Results are returned in relevance order.

### Notes

- Search runs against memory name, content, and excerpt index
- Results are scoped to the current authenticated user
- The soul and index are not returned in search results (use `resources/read` directly)

### Example

```
User: "What do we know about how I like to receive feedback?"
AI: [calls search_memory("feedback communication preferences")]
AI: "Based on your stored memories, you prefer written feedback over verbal,
    with specific actionable items rather than general observations..."
```

---

## Rate limits

Write operations (`remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`) are limited to **30 per minute** per user.

Read operations (`resources/list`, `resources/read`, `search_memory`) are limited to **60 per minute** per user.

When a rate limit is hit, the response is:

```json
{
  "code": "rate_limited",
  "message": "Too many requests. Please slow down.",
  "retry_after": 30
}
```

---

## Context parameter

Most tools accept an optional `context` parameter — a brief explanation (max 200 characters) of why the AI is performing the operation. This appears in the **History** audit log in the WordPress admin, making it easy to review and understand what your AI has been doing.

Good context strings:
- `"User explicitly stated this preference"`
- `"Project context changed after user mentioned deadline moved"`
- `"Correcting outdated information based on new statement"`

Bad context strings:
- `"storing memory"` — too generic
- A 500-word explanation — too long
