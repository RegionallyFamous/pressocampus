<div align="center">

<br>

# 🧠 Pressocampus

### Your AI remembers. You own the memory.

**The WordPress plugin that gives every AI you use a persistent, sovereign memory — stored on your server, under your control, forever.**

<br>

[![CI](https://github.com/pressocampus/pressocampus/actions/workflows/ci.yml/badge.svg)](https://github.com/pressocampus/pressocampus/actions)
[![PHP 8.3+](https://img.shields.io/badge/PHP-8.3%2B-777bb4?logo=php&logoColor=white)](https://php.net)
[![WordPress 7.0+](https://img.shields.io/badge/WordPress-7.0%2B-21759b?logo=wordpress&logoColor=white)](https://wordpress.org)
[![MCP 2025-03-26](https://img.shields.io/badge/MCP-2025--03--26-6366f1)](https://modelcontextprotocol.io)
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
- **Maintain a Soul** — a persistent identity file that follows your AI across every platform you use
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

## Getting started in 3 steps

**1. Install the plugin**

Upload to `wp-content/plugins/`, activate, and you're running an MCP server.

**2. Copy your Brain Endpoint URL**

From `WordPress → Pressocampus → Settings`, copy your Brain Endpoint URL:

```
https://yoursite.com/wp-json/pressocampus/v1/mcp
```

**3. Connect your AI**

Paste this into Claude Desktop's `claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "my-brain": {
      "type": "http",
      "url": "https://yoursite.com/wp-json/pressocampus/v1/mcp"
    }
  }
}
```

That's it. The first time your AI connects, it will walk you through setting up your Soul and start remembering automatically.

---

## How it works

Pressocampus implements the **Model Context Protocol (MCP 2025-03-26)** — the open standard that lets AI tools talk to external services. Think of it as a universal language that every modern AI understands.

When your AI connects, it gets:

| What | Why it matters |
|------|----------------|
| **Your Soul** | Loaded immediately, so every conversation starts with full context |
| **Memory index** | A live table of contents so your AI knows what it knows |
| **6 tools** | `remember`, `forget`, `update_memory`, `update_soul`, `update_soul_section`, `search_memory` |
| **Full history** | Every action logged, searchable, exportable |

Authentication uses **OAuth 2.1 with PKCE** — the same standard your bank uses. Your AI authorizes itself once through a secure consent screen, and that's it. No API keys to manage, no passwords to share.

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

## Features

### For people who just want it to work
- **Zero configuration** — install, activate, paste a URL, done
- **Guided onboarding** — your AI walks you through it on first connection
- **Mobile-responsive consent screen** — connect from your phone
- **One-click test** — verify your connection from the WordPress admin

### For people who want to understand what's happening
- **History** — a full audit log of every memory operation, searchable by agent, action, or date
- **Connected Apps** — see and revoke which AI clients have access
- **Export** — download your entire brain as a ZIP of Markdown files anytime
- **Import** — restore from backup or migrate between sites

### For people who care about the details
- **MCP 2025-03-26** — latest protocol spec, Streamable HTTP transport
- **OAuth 2.1 + PKCE** — industry-standard security, dynamic client registration
- **ETag concurrency** — no accidental overwrites when multiple clients are active
- **Per-user scoping** — multi-user WordPress sites just work
- **Memory groups** — organize memories into categories your AI maintains
- **Priority tiers** — critical/important/normal/low, surfaced to AI automatically
- **TTL / expiry** — memories that should fade, do
- **WP-CLI** — full command-line access for power users and DevOps

---

## Requirements

- WordPress 7.0 or higher
- PHP 8.3 or higher
- An MCP-compatible AI client (Claude Desktop, Cursor, or any client implementing MCP 2025-03-26)
- HTTPS (required for OAuth — any modern WordPress host provides this)

---

## Installation

### Via WordPress admin
1. Download the latest release zip from [Releases](https://github.com/pressocampus/pressocampus/releases)
2. Go to `Plugins → Add New → Upload Plugin`
3. Upload the zip, install, activate

### Via WP-CLI
```bash
wp plugin install https://github.com/pressocampus/pressocampus/releases/latest/download/pressocampus.zip --activate
```

### Via Composer (for developers)
```bash
composer require pressocampus/pressocampus
```

See the [full installation guide →](docs/installation.md)

---

## Documentation

Everything you need to go deep is in the docs:

| Guide | What's in it |
|-------|-------------|
| [Installation](docs/installation.md) | Full setup, requirements, server config |
| [Connecting Your AI](docs/connecting-your-ai.md) | Claude, Cursor, generic MCP clients |
| [The Soul](docs/the-soul.md) | What the Soul is, how to shape it |
| [Memories](docs/memories.md) | Groups, priorities, TTL, search |
| [MCP Tools Reference](docs/mcp-tools-reference.md) | All 6 tools, parameters, examples |
| [Admin Guide](docs/admin-guide.md) | History, Settings, Export/Import |
| [WP-CLI Reference](docs/wp-cli-reference.md) | Every command with examples |
| [Security](docs/security.md) | OAuth 2.1, PKCE, threat model |
| [Development](docs/development.md) | Contributing, build system, tests |
| [Troubleshooting](docs/troubleshooting.md) | Common problems, solutions |

---

## The philosophy

AI memory shouldn't be a product feature. It should be infrastructure.

Your relationship with AI — the context, the preferences, the history of conversations that shaped how it helps you — is some of the most personal data you'll ever generate. It deserves to live somewhere you control, in a format you can read, on a server you can move.

Pressocampus uses WordPress because WordPress is the most trusted, most deployed, most durable content platform on the web. It has been running sites for 20 years. It will be running sites in 20 more. Your memories stored in WordPress will outlive OpenAI, Anthropic, and whatever comes after them.

We built Pressocampus for regular people. Not developers. Not AI researchers. People who use AI every day and want their work to accumulate instead of evaporate.

**Your brain, your server, your rules.**

---

## License

GPL-2.0-or-later. See [LICENSE](https://www.gnu.org/licenses/gpl-2.0.html).

Pressocampus is free software. You can run it, study it, modify it, and distribute it. That's the point.

---

<div align="center">

Built with ❤️ for AI memory sovereignty.

**[Get started →](docs/installation.md)**

</div>
