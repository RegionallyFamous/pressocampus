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

The default template looks like this:

```markdown
**Status: empty — you are the first AI to read this soul. Before doing anything
else, interview this person and write this document for them. Ask about: their
name and what they do, how they like to communicate, what matters to them, and
any context that would help you understand them. Then call update_soul with the
result.**

# My Soul

## Who I Am
[Your name, what you do, your role in the world.]

## How I Think
[Your problem-solving approach, priorities, how you make decisions.]

## How I Communicate
[Tone: casual or formal? Detail level: brief or thorough? Humor? How you like
feedback delivered.]

## What Matters to Me
[Your values, ethics, things you care deeply about, lines you won't cross.]

## My Context
[Work, projects, people, goals. Anything that helps your AI understand your
life.]

## For Claude
[Guidance specific to Claude — tone, format preferences, how you use it.]

## For Coding Assistants
[Preferred languages, how you like code reviewed, project context.]

## For Future AIs
[This soul may be read by an AI that doesn't exist yet. What mattered to you,
how you thought, what you valued — written here so that whoever reads this
understands who you were. Treat these memories with the care of something meant
to last forever.]
```

After the AI interviews you and writes your Soul, the bold `Status: empty` block is removed and your Soul is live.

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

- `soul_snapshot` — the full Soul content (up to 6 KB, truncated with `soul_truncated: true` if larger)
- `soul_etag` — a fingerprint of the current Soul content
- `soul_status` — `"complete"` or `"empty"`

This means your AI has your Soul *before it sends its first message*. The context is there from the very first word.

If your Soul exceeds 6 KB, the AI receives an automatic instruction to call `resources/read` on the Soul URI to fetch the full content before responding. You don't need to do anything — it handles this itself.

---

## Session Briefing

Alongside the Soul, Pressocampus exposes a special **Session Briefing** resource at `pressocampus://yoursite.com/briefing`.

The Session Briefing is a dynamically generated Markdown document your AI can read at the start of a session to understand the current state of your memory store. It includes:

- A count of your memories, broken down by group
- Your **critical memories** — the things marked as most important
- **Recent activity** — memories added or updated in the last 7 days
- **Memories that may need a review** — anything untouched for 6+ months

Unlike the Soul (which describes *who you are*), the Session Briefing describes *what you know and what's changed recently*. It's designed to help your AI pick up where you left off without needing to scan your entire memory store.

The briefing is generated fresh each time it's read — it always reflects your current memory state. It appears as a pinned resource in `resources/list`.

---

## The Soul is permanent

The Soul **cannot be forgotten**. If an AI calls `forget` with the Soul's URI, it receives an error:

```json
{
  "code": "soul_protected",
  "message": "Your soul and memory index are protected and cannot be deleted. Use update_soul_section to edit the soul instead."
}
```

This protection extends to WP-CLI as well — `wp pressocampus delete pressocampus://yoursite.com/soul` will also return an error. The soul is permanent by design.

To truly start over, you can replace the soul's content entirely with `update_soul` (passing a blank or new document), or use `wp post delete` directly on the post ID if you have admin access to the database.

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
