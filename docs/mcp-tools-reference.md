# MCP Tools Reference

Pressocampus exposes **8 tools** to connected AI clients via the MCP `tools/list` and `tools/call` methods. These are the only way memories are written, updated, or deleted — there is no UI for writing memories.

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
| `expires_at` | string | No | ISO 8601 datetime when this memory should expire, e.g. `2026-12-31T23:59:59Z` |
| `context` | string | No | Why you're storing this — appears in History. Max 200 chars. |

### Returns

```json
{
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "name": "Prefers TypeScript over JavaScript"
}
```

If an **exact duplicate** already exists (same content hash), no new memory is created and the response includes a `note` field:

```json
{
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "name": "Prefers TypeScript over JavaScript",
  "note": "This memory already exists (exact duplicate). No new memory created."
}
```

If the content is **similar but not identical** to existing memories, the new memory is still created but the response includes advisory fields:

```json
{
  "uri": "pressocampus://yoursite.com/memory/xyz67890",
  "name": "Prefers TypeScript",
  "possible_related": {
    "uri": "pressocampus://yoursite.com/memory/abc12345",
    "name": "Prefers TypeScript over JavaScript",
    "excerpt": "Strongly prefers TypeScript..."
  }
}
```

A `possible_contradiction` field may also appear if a semantically conflicting memory is found.

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
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "name": "Prefers TypeScript over JavaScript",
  "deleted": true
}
```

### Errors

| Code | Meaning |
|------|---------|
| `not_found` | No memory with that URI exists (or you don't own it) |
| `soul_protected` | Attempted to forget the soul or index |
| `rate_limit_exceeded` | Too many write operations — slow down |

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
| `etag_conflict` | ETag mismatch — memory was updated since you last read it |
| `rate_limit_exceeded` | Too many write operations |

---

## `update_soul`

Write or fully rewrite the Soul — the AI's identity document covering its name, character, voice, values, and what it knows about this person.

**When to use:** First-time setup (when `soul_status` is `"empty"`) or when the soul needs complete restructuring. For targeted updates to a single section, use `update_soul_section` instead. This creates the soul if it doesn't exist.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `content` | string | Yes | The full updated soul content (Markdown) |
| `etag` | string | No | ETag for optimistic concurrency |
| `context` | string | No | Why you're updating the soul |

### Returns

```json
{
  "uri": "pressocampus://yoursite.com/soul",
  "etag": "e5f6a7b8"
}
```

### Notes

- If the soul previously had `Status: empty`, that line is removed after the first real update
- WordPress admin receives an email notification if the soul is updated (configurable)

---

## `update_soul_section`

Update a single `## Section` of the soul. **This is the preferred method for soul updates.**

**When to use:** Whenever the AI learns something new about this person (update relationship sections like `This Person` or `How We Work Together`) or when the AI's own sense of its character or voice evolves (update identity sections like `My Character` or `My Voice`). Write in the AI's voice: "I've learned they prefer directness." Faster and safer than a full soul rewrite.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `section` | string | Yes | The `## Heading` text, e.g. `"How We Work Together"` |
| `content` | string | Yes | New body for that section (write in the AI's voice) |
| `context` | string | No | Why you're updating this section |

### Returns

```json
{
  "uri": "pressocampus://yoursite.com/soul",
  "etag": "f1a2b3c4",
  "section": "How I Communicate",
  "created": false
}
```

`created: true` means the named section did not exist and was appended as new. `created: false` means an existing section was updated in-place. Use this to detect heading typos — if you expected to update an existing section and `created` is `true`, the heading didn't match.

### Notes

- If the specified section doesn't exist, it's appended to the end of the soul and `created: true` is returned
- Section matching is case-insensitive and trims whitespace
- The soul's ETag is automatically updated after a section change

### Examples

Updating a relationship section:

```
User: "Actually, I hate bullet points. Just write in prose."
AI: [calls update_soul_section]
  section: "How We Work Together"
  content: "I've learned they can't stand bullet points — prose only. Lead
  with the answer, then explain. Short sentences. Active voice. No padding,
  no affirmations. Direct to the point of blunt is fine."
  context: "User stated preference against bullet points"
```

Updating an identity section:

```
AI: [after many sessions, the voice section needs sharpening]
  [calls update_soul_section]
  section: "My Voice"
  content: "Short sentences. Prose, not bullets. I open with the point.
  No throat-clearing, no affirmations, no summaries of what the person
  just said. Active voice throughout."
  context: "Voice has sharpened over many sessions"
```

---

## `search_memory`

Search memories by keyword.

**When to use:** When the user asks a question that might be answered by stored memories. Call this before answering factual questions about the user's preferences, history, or decisions. Also call this before `remember` to avoid creating duplicates.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `query` | string | Yes | Search terms |
| `group` | string | No | Limit search to a specific group |
| `limit` | integer | No | Maximum results to return (default: 10, max: 50) |
| `context` | string | No | Why you're searching |

### Returns

```json
{
  "results": [
    {
      "uri": "pressocampus://yoursite.com/memory/abc12345",
      "name": "Prefers TypeScript over JavaScript",
      "excerpt": "Strongly prefers TypeScript over JavaScript for all projects.",
      "priority": "important",
      "confidence": "high",
      "updated_at": "2025-03-15T14:22:00Z"
    }
  ],
  "count": 1
}
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

## `list_memories`

Browse your memory store — useful for getting an overview of what's been stored, paginating through a group, or building a summary.

**When to use:** When the user asks "what do you know about me?", when reviewing a specific group, or when you need to find a memory whose URI you don't have. Count-based browsing, not a substitute for `search_memory` when you have a specific query.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `group` | string | No | Filter to a specific group. Omit for all memories. |
| `sort` | string | No | `date_desc` (default), `date_asc`, or `name_asc` |
| `limit` | integer | No | Results per page. 1–50, default 20. |
| `cursor` | string | No | Pagination cursor from previous response's `next_cursor` |
| `context` | string | No | Why you're listing — appears in History |

### Returns

```json
{
  "items": [
    {
      "uri": "pressocampus://yoursite.com/memory/abc12345",
      "name": "Prefers TypeScript over JavaScript",
      "group": "technical",
      "created_at": "2025-03-10T09:00:00Z",
      "updated_at": "2025-03-15T14:22:00Z",
      "priority": "important",
      "confidence": "high",
      "excerpt": "Strongly prefers TypeScript over JavaScript for all projects."
    }
  ],
  "count": 1,
  "page": 1,
  "next_cursor": "eyJwYWdlIjoyfQ=="
}
```

To fetch the next page, pass `next_cursor` as the `cursor` parameter. When there is no `next_cursor` in the response, you've reached the last page.

### Notes

- The soul and index are never included in results
- Items are scoped to the current authenticated user
- This is a **read** operation (counts against the 60/min read limit)

### Example

```
User: "What do you know about my technical preferences?"
AI: [calls list_memories]
  group: "technical"
  sort: "date_desc"
  limit: 20
AI: "I have 7 memories in your technical group: TypeScript preference,
    Postgres for all projects, prefers dark mode, ..."
```

---

## `tag_memory`

Change a memory's group or priority — useful for reorganizing your memory store or correcting how a memory was originally categorized.

**When to use:** When the user asks to move a memory to a different group, or to change its importance level. Also useful when the AI realizes a memory was filed under the wrong group on creation.

### Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `uri` | string | Yes | The URI of the memory to retag |
| `group` | string | No | New group. Provide at least one of `group` or `priority`. |
| `priority` | string | No | `critical`, `important`, `normal`, or `low` |
| `context` | string | No | Why you're changing this — appears in History |

### Returns

```json
{
  "uri": "pressocampus://yoursite.com/memory/abc12345",
  "group": "work",
  "priority": "important"
}
```

Only the fields you changed are included in the response.

### Errors

| Code | Meaning |
|------|---------|
| `not_found` | No memory with that URI (or you don't own it) |
| `protected_resource` | Attempted to retag the soul or index |
| `nothing_to_update` | Neither `group` nor `priority` was provided |
| `rate_limit_exceeded` | Too many write operations — slow down |

### Example

```
User: "Actually, that memory about my Postgres preference should be in the
       technical group, not projects."
AI: [calls tag_memory]
  uri: "pressocampus://yoursite.com/memory/xyz67890"
  group: "technical"
  context: "User requested reclassification from projects to technical"
```

---

## Rate limits

Write operations (`remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, `tag_memory`) are limited to **30 per minute** per user.

Read operations (`resources/list`, `resources/read`, `search_memory`) are limited to **60 per minute** per user.

When a rate limit is hit, the tool returns an error response:

```json
{
  "isError": true,
  "content": [
    {
      "type": "text",
      "text": "Write rate limit reached (30/min). Please wait a moment and try again."
    }
  ],
  "code": "rate_limit_exceeded"
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
