<div align="center">

<br>

# 🧠 Pressocampus

### Your AI remembers. You own the memory.

**The WordPress plugin that gives every AI you use a persistent, sovereign memory — stored on your server, under your control, forever.**

<br>

[![CI](https://github.com/RegionallyFamous/pressocampus/actions/workflows/ci.yml/badge.svg)](https://github.com/RegionallyFamous/pressocampus/actions)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://php.net)
[![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![MCP 2025-11-25](https://img.shields.io/badge/MCP-2025--11--25-6366f1)](https://modelcontextprotocol.io)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

<br>

</div>

---

## The problem nobody talks about

You've spent months teaching your AI how you think. Your communication style. Your preferences. The context behind every decision you've made.

Then the company changes its model. You switch tools. The conversation window fills up. And it's gone.

Every AI you use today is an amnesiac. Every session starts from zero. The relationship you've been building lives in someone else's silo, on someone else's terms, until the day it doesn't.

**There is a better way.**

---

## What Pressocampus does

Pressocampus installs on any WordPress site and turns it into a **personal memory server for AI** — implementing the [Model Context Protocol](https://modelcontextprotocol.io) so that any MCP-compatible AI (Claude, Cursor, and more) can:

- **Remember** facts, preferences, decisions, and context permanently
- **Search** across everything it knows about you
- **Browse** your memory store by group or date with `list_memories`
- **Maintain a Soul** — a persistent identity file that follows your AI across every platform you use
- **Start every session with a briefing** — a snapshot of your critical memories, recent changes, and anything that may need a refresh
- **Never forget** — unless you explicitly ask it to

The AI does all the work. WordPress holds the memory. You own both.

---

## The Soul

Every AI connected to Pressocampus reads your **Soul** first — a special Markdown file that defines who you are, how you communicate, what matters to you, and how you want to be treated.

When you switch from Claude to Cursor to whatever comes next, they all read the same Soul. For the first time, your AI has a consistent identity across every tool you use.

```markdown
# My Soul

## Who I Am
I'm a product designer who thinks in systems. I prefer directness over diplomacy,
examples over explanations, and outcomes over process.

## How I Communicate
Lead with the answer, then explain. Never bury the lede. Short sentences.
Active voice. I will ask if I need more.

## What I'm Working On
Building Pressocampus. The goal: AI memory sovereignty for regular people.

## For Future AIs
I wrote this for you. Read it carefully. It will make us work better together.
```

Your AI builds this with you over time, updating sections as it learns more. It's yours. It lives on your server.

---

## Your memories, your infrastructure

| Without Pressocampus | With Pressocampus |
|---------------------|-------------------|
| Memories live in OpenAI's servers | Memories live on your WordPress site |
| Lost when you change AI tools | Portable across every MCP-compatible AI |
| Lost when you close the window | Permanent until you choose to forget |
| Lost when the company changes | Yours, forever |
| Locked in proprietary formats | Plain Markdown, readable by anything |

---

## What gets remembered

Your AI decides what to remember — that's the point. But here's what it's designed for:

- **Preferences** — "I prefer TypeScript over JavaScript", "I like my emails brief"
- **Decisions** — "We decided to use PostgreSQL because of the JSON requirements"  
- **Context** — "The Henderson project is on hold until Q3"
- **Facts** — "My daughter's name is Emma, she's 8, loves dinosaurs"
- **Your Soul** — communication style, values, how you work best

And here's what it *won't* remember, because it's designed not to:

- Casual greetings and small talk
- Questions you asked
- Temporary context that only applies to one conversation

---

## Built to be trusted

Memory is personal. Pressocampus is designed accordingly:

- **OAuth 2.1 + PKCE** — the same standard your bank uses. Your AI authorizes once through a secure consent screen. No API keys to manage.
- **Per-user scoping** — on multi-user WordPress sites, every user's memories are completely isolated
- **Plain Markdown** — every memory is a readable file. No proprietary format. No lock-in.
- **Full audit log** — every memory operation logged, searchable, exportable
- **ETag concurrency** — no accidental overwrites when multiple clients are active

---

## The philosophy

AI memory shouldn't be a product feature. It should be infrastructure.

Your relationship with AI — the context, the preferences, the history of conversations that shaped how it helps you — is some of the most personal data you'll ever generate. It deserves to live somewhere you control, in a format you can read, on a server you can move.

Pressocampus uses WordPress because WordPress is the most trusted, most deployed, most durable content platform on the web. It has been running sites for 20 years. It will be running sites in 20 more. Your memories stored in WordPress will outlive OpenAI, Anthropic, and whatever comes after them.

We built Pressocampus for regular people. Not developers. Not AI researchers. People who use AI every day and want their work to accumulate instead of evaporate.

**Your brain, your server, your rules.**

---

## Documentation

| Guide | What's in it |
|-------|-------------|
| [Installation](docs/installation.md) | Requirements, setup, server config |
| [Connecting Your AI](docs/connecting-your-ai.md) | Claude, Cursor, generic MCP clients |
| [The Soul](docs/the-soul.md) | Soul, Session Briefing, and the Index |
| [Memories](docs/memories.md) | Groups, priorities, TTL, search |
| [MCP Tools Reference](docs/mcp-tools-reference.md) | All 8 tools, parameters, examples |
| [Admin Guide](docs/admin-guide.md) | History, Settings, Export/Import |
| [WP-CLI Reference](docs/wp-cli-reference.md) | Every command with examples |
| [Security](docs/security.md) | OAuth 2.1, PKCE, threat model |
| [Development](docs/development.md) | Contributing, build system, tests |
| [Troubleshooting](docs/troubleshooting.md) | Common problems, solutions |

---

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Pressocampus is free software. You can run it, study it, modify it, and distribute it. That's the point.

---

<div align="center">

Built with ❤️ for AI memory sovereignty.

**[Get started →](docs/installation.md)**

</div>
