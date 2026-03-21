# Memories

Memories are the foundation of Pressocampus. Everything your AI knows about you — except the Soul — lives here.

The Soul is different: it's the AI's own document about itself and your relationship, written in its voice, for future instances of itself. Memories and the Soul are complementary — memories are discrete facts, decisions, and preferences; the Soul is the ongoing account of the relationship as a whole.

---

## What is a memory?

A memory is a single piece of information stored permanently on your WordPress site. Each memory has:

| Field | Description |
|-------|-------------|
| **URI** | A unique identifier, e.g. `pressocampus://yoursite.com/memory/abc12345` |
| **Name** | A short display title (auto-generated if not provided) |
| **Content** | The memory itself, in plain text or Markdown |
| **Group** | An optional category for organization |
| **MIME type** | Always `text/markdown` |
| **Priority** | `critical`, `important`, `normal`, or `low` |
| **Confidence** | `high`, `medium`, or `low` — how certain the AI was |
| **Related** | URIs of related memories (knowledge graph) |
| **Expires at** | Optional expiry date — useful for time-sensitive context |
| **Created/Updated** | Timestamps |

---

## How memories are created

Memories are created by your AI calling the `remember` tool. Your AI decides what's worth remembering — you don't need to do anything.

Your AI is designed to remember:

- **Preferences** — "I prefer email over Slack", "short answers please"
- **Personal facts** — "I have a 4-year-old named Sam", "I live in Austin"
- **Decisions** — "We chose Postgres over MySQL because of JSONB support"
- **Context** — "The redesign is on hold until the new CTO starts"
- **Working style** — "I like to see the answer first, then the reasoning"

And designed *not* to remember:

- Questions and greetings
- Casual, transient conversation
- Relationship-level observations that belong in the soul (e.g. "this person prefers directness" → update the soul; "they use TypeScript for all projects" → remember it)

### Duplicate detection

Before creating a new memory, the AI calls a duplicate check. If a very similar memory already exists, it updates the existing one rather than creating a duplicate. This keeps your memory store clean over time.

---

## Groups

Groups are like folders. Your AI assigns memories to groups to keep things organized.

When you first connect, there are no groups — they're created automatically as your AI decides to organize things. Common groups that tend to emerge:

- `work` — professional context and preferences
- `personal` — family, life, personal facts
- `projects` — per-project decisions and context
- `technical` — tech stack preferences, code patterns
- `health` — medical preferences, fitness context

Your AI will reuse existing groups rather than creating new ones, so your taxonomy stays consistent. You can see your current groups in the `initialize` metadata (`meta.groups`) every time a client connects.

---

## Priority tiers

Every memory has a priority that tells your AI how important it is:

| Priority | When to use |
|----------|-------------|
| `critical` | Core identity, non-negotiable preferences, things that affect every interaction |
| `important` | Frequently-relevant context that should be surfaced proactively |
| `normal` | Standard memories — referenced when relevant (default) |
| `low` | Nice-to-know, rarely changes behavior, background context |

High-priority memories are returned first in `resources/list`, so your AI sees the most important context before token limits apply.

---

## Confidence levels

When your AI stores a memory, it also records how confident it was in the inference:

| Confidence | Meaning |
|------------|---------|
| `high` | Explicitly stated by you |
| `medium` | Reasonably inferred from context |
| `low` | Tentative — the AI isn't sure |

Low-confidence memories may be updated or removed as better information becomes available.

---

## TTL and expiry

Some information has a natural shelf life. Memories can have an optional `expires_at` post meta value, after which they're marked with a custom `pressocampus_expired` status by an hourly WP-Cron job.

Expired memories are:
- Hidden from `resources/list`
- Not returned in `search_memory`
- Not counted toward your memory limit
- Retained in the database for audit purposes

The `remember` tool accepts an optional `expires_at` parameter — pass an ISO 8601 datetime (e.g. `2026-12-31T23:59:59Z`) and the memory will expire automatically on that date. TTL can also be set after creation with `wp post meta update <post-id> _pressocampus_expires_at <ISO-8601-date>` via WP-CLI.

---

## Memory limits

By default, each user can store up to **1,000 memories**. This is configurable in `Settings → Advanced → Memory limit`.

When the limit is approached:
- Your AI is notified via the `initialize` metadata
- Your AI can proactively archive or consolidate old memories

---

## Searching memories

Your AI can search your memories with the `search_memory` tool. Searches run against the memory name, content, and an excerpt index — full-text search across everything you've ever stored.

```
User: "What was our reasoning for using TypeScript on the authentication service?"
AI: [calls search_memory("TypeScript authentication reasoning")]
AI: "You decided on TypeScript for the auth service in March primarily for the interface definitions — you mentioned it made the token shape explicit and reduced bugs at the API boundary."
```

Search is scoped to the current user — your AI can never search memories belonging to another user.

---

## Related memories

Memories can reference each other via the `related` field — a list of URIs that are conceptually connected. This forms a lightweight knowledge graph.

For example:
- Memory: "I use Postgres for all projects" → related to → Memory: "On the analytics project we specifically wanted JSONB support"
- Memory: "I dislike long meetings" → related to → Memory: "Async-first communication preference"

When your AI updates or deletes a memory, it updates related references automatically.

---

## Exporting memories

You can export all your memories at any time:

- **From the admin:** `Settings → Advanced → Download Brain` — downloads a `pressocampus-brain.json` file (or a ZIP containing it) with all memories and metadata
- **From WP-CLI:** `wp pressocampus export --format=markdown-folder --output=./brain/`

The export format is plain Markdown, readable by any text editor, importable by any future version of Pressocampus.

---

## Importing memories

Restore from a previous export or migrate from another site:

- **From WP-CLI:** `wp pressocampus import --file=brain.json`

See [WP-CLI Reference →](wp-cli-reference.md) for full options including domain migration.
