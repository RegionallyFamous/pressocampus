# The Soul

The Soul is the most important feature in Pressocampus. Everything else is memory — facts, decisions, context. The Soul is *identity*.

---

## What the Soul is

The Soul is a special Markdown file stored at `pressocampus://yoursite.com/soul`. It's a permanent, protected memory that every AI reads first, every single time it connects.

Think of it as a letter you've written to every AI you'll ever use. It answers the question: *"How should you work with me?"*

A good Soul contains:
- Who you are
- How you like to communicate
- What you're working on
- How you make decisions
- What you find annoying
- What matters to you most

When your AI reads this at the start of every session, it starts at your level instead of from scratch.

---

## How the Soul is created

On the first time any AI connects to your Pressocampus installation:

1. The AI receives a `soulStatus: "empty"` signal in the connection metadata
2. The AI creates an initial Soul from a template that uses `[placeholder]` values
3. It immediately introduces itself and asks you to fill in the placeholders

The default template looks something like this:

```markdown
# [Your Name]'s Soul

Status: empty

## Who I Am
[A brief description of who you are and what you do]

## How I Communicate
[Your preferred communication style]

## What I'm Working On
[Current projects or focus areas]

## How I Make Decisions
[Your decision-making approach and values]

## What I Find Helpful
[Things that make you more productive]

## What I Find Unhelpful
[Things you want the AI to avoid]

## For Future AIs
I wrote this so that every AI I work with starts from the same understanding.
Read it carefully. Update it as you learn more about me.
```

After you fill in the placeholders, your AI removes the `Status: empty` signal and your Soul is live.

---

## How the Soul is updated

Your AI updates your Soul through two MCP tools:

### `update_soul_section` — for most changes

This is the preferred method. It updates a single `## Section` without touching anything else. Use this when the AI learns something new about you.

Example: you tell Claude you've changed jobs. It calls `update_soul_section` to update the **What I'm Working On** section. Everything else stays exactly as it was.

### `update_soul` — for full restructuring

This replaces the entire Soul. Your AI should only do this when you ask it to completely rewrite your Soul — for example, after a major life change, or when you want to start fresh with a better structure.

The AI always uses ETag-based concurrency checking, so if you have two clients connected simultaneously, they can't accidentally overwrite each other's changes.

---

## The Soul in `initialize`

Every time an AI connects to Pressocampus, the connection handshake (`initialize`) includes:

- `soul_snapshot` — the full Soul content (up to 2KB, truncated with `soul_truncated: true` if larger)
- `soul_etag` — a fingerprint of the current Soul content
- `soul_status` — `"exists"` or `"empty"`

This means your AI has your Soul *before it sends its first message*. The context is there from the very first word.

---

## The Soul is permanent

The Soul **cannot be forgotten**. If an AI calls `forget` with the Soul's URI, it receives an error:

```json
{
  "code": "protected_resource",
  "message": "The soul cannot be forgotten. It can only be updated."
}
```

This protection exists because the Soul is built up over time through real conversations. Accidentally deleting it would be a significant loss.

To intentionally remove your Soul, use the WordPress admin panel or WP-CLI:

```bash
wp pressocampus delete pressocampus://yoursite.com/soul --yes
```

---

## The Soul is per-user

On a multi-user WordPress site, every user has their own Soul. Alice's Soul doesn't affect Bob's, and vice versa. Each person's connected AI works within their own context.

---

## The Soul is site-namespaced

The Soul's URI includes your site's hostname: `pressocampus://yoursite.com/soul`. This means if you migrate your site to a new domain, you use the `migrate-domain` WP-CLI command to update all URIs — including the Soul — to reflect the new hostname.

```bash
wp pressocampus migrate-domain --from=old.com --to=new.com
```

---

## The Index

Alongside the Soul, Pressocampus maintains a second protected memory at `pressocampus://yoursite.com/index`.

The Index is a machine-readable table of contents — it lists your memory groups, total counts, and recent memories. Your AI reads it to know what it knows, so it can decide whether a `search_memory` call is worth making.

The Index is rebuilt automatically whenever memories change (debounced to avoid performance spikes). Like the Soul, it cannot be forgotten — only read.

---

## Tips for a great Soul

**Write it for the AI, not for yourself.** The Soul isn't a journal. It's instructions. Be specific about how you want things done.

**Keep sections focused.** One idea per section. The AI updates sections individually, so clear boundaries make updates cleaner.

**Include negative preferences.** "Don't pad responses with affirmations" is as useful as "be concise." Your AI can't know what you hate unless you tell it.

**Update it over time.** Your Soul should evolve as your AI learns more about you. The best Souls are built through months of real conversation, not in one sitting.

**Trust your AI to update it.** When you tell your AI something significant about how you work, it should offer to update your Soul. If it doesn't, just ask: "Add that to my Soul."
