# WP-CLI Reference

Pressocampus provides a full suite of WP-CLI commands for managing memories, running imports and exports, reviewing the audit log, and maintaining your installation.

All commands follow the pattern: `wp pressocampus <command> [options]`

---

## `wp pressocampus list`

List memories for a user.

### Options

| Option | Description |
|--------|-------------|
| `--user=<user>` | WordPress user ID or login. Defaults to the current user. |
| `--group=<group>` | Filter by group/category. |
| `--format=<format>` | Output format: `table` (default), `json`, `csv`. |

### Output columns

`uri`, `name`, `group`, `priority`, `confidence`, `updated`

### Examples

```bash
wp pressocampus list
wp pressocampus list --group=work --format=json
wp pressocampus list --user=nick --format=csv
```

---

## `wp pressocampus get`

Print the full content of a memory by URI.

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `<uri>` | Yes | The full `pressocampus://` URI |

### Examples

```bash
wp pressocampus get pressocampus://mysite.com/memory/abc123
wp pressocampus get pressocampus://mysite.com/soul
```

---

## `wp pressocampus delete`

Hard-delete a memory by URI. This is permanent and bypasses the trash.

The soul (`pressocampus://yoursite.com/soul`) and index (`pressocampus://yoursite.com/index`) cannot be deleted — even with this command.

### Arguments

| Argument | Required | Description |
|----------|----------|-------------|
| `<uri>` | Yes | The full `pressocampus://` URI to delete |

### Options

| Option | Description |
|--------|-------------|
| `--yes` | Skip the confirmation prompt. |

### Examples

```bash
wp pressocampus delete pressocampus://mysite.com/memory/abc123
wp pressocampus delete pressocampus://mysite.com/memory/abc123 --yes
```

---

## `wp pressocampus export`

Export memories to a file.

### Options

| Option | Description |
|--------|-------------|
| `--user=<user>` | WordPress user ID or login. Defaults to the current user. |
| `--format=<format>` | `json` (default) or `markdown-folder`. |
| `--output=<path>` | Output path. For `json`: defaults to `./pressocampus-export-{date}.json`. For `markdown-folder`: defaults to `./pressocampus-export-{date}/`. |

### Formats

**`json`** — Exports all memories (including the soul) as a single JSON file with full metadata. Suitable for re-importing.

**`markdown-folder`** — Exports each memory as an individual `.md` file with YAML front matter, and the soul as `SOUL.md`. Useful for reading in a text editor or version-controlling your memories.

### Examples

```bash
wp pressocampus export
wp pressocampus export --format=json --output=brain.json
wp pressocampus export --format=markdown-folder --output=./brain/
wp pressocampus export --user=nick --format=markdown-folder
```

---

## `wp pressocampus import`

Import memories from a JSON file or Markdown folder.

All imported memories are upserted — if a memory with the same URI already exists, it is updated. If it doesn't exist, it is created.

For the soul specifically, the command will prompt for confirmation before overwriting unless `--yes` is passed.

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--file=<path>` | Yes | Path to a `.json` file or a directory of `.md` files. |
| `--user=<user>` | No | Target WordPress user ID or login. Defaults to the current user. |
| `--yes` | No | Skip the soul-overwrite confirmation prompt. |

### Examples

```bash
wp pressocampus import --file=brain.json
wp pressocampus import --file=brain.json --user=nick --yes
wp pressocampus import --file=./brain/
```

---

## `wp pressocampus migrate-domain`

Update all memory URIs when a site's domain has changed.

This command rewrites `pressocampus://<old-host>/...` URIs to `pressocampus://<new-host>/...` in:
- Post meta (`_pressocampus_uri`)
- The resource index table
- Related URI references (`_pressocampus_related`)

### Options

| Option | Required | Description |
|--------|----------|-------------|
| `--from=<host>` | Yes | The old hostname (e.g. `old.com`) |
| `--to=<host>` | Yes | The new hostname (e.g. `new.com`) |
| `--dry-run` | No | Preview changes without applying them. |

### Examples

```bash
wp pressocampus migrate-domain --from=old.com --to=new.com
wp pressocampus migrate-domain --from=old.com --to=new.com --dry-run
```

---

## `wp pressocampus flush-cache`

Flush the Pressocampus object cache group (or the full object cache if the group-flush function isn't available).

Use this if the resource index appears stale after direct database changes.

### Examples

```bash
wp pressocampus flush-cache
```

---

## `wp pressocampus audit`

Show the Pressocampus audit log.

Output columns: `date`, `agent`, `action`, `memory`, `context`

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--days=<n>` | `7` | Show entries from the last N days. |
| `--user=<user>` | (all users) | Filter by WordPress user ID or login. |
| `--format=<format>` | `table` | Output format: `table`, `json`, or `csv`. |

### Examples

```bash
wp pressocampus audit
wp pressocampus audit --days=30
wp pressocampus audit --user=nick --format=csv > audit.csv
```

---

## `wp pressocampus stats`

Show memory statistics for all users.

Outputs three sections:

**Memories per User** — a table showing each user's login, display name, memory count (excluding soul and index), and soul status (`empty` or `complete`).

**DB Table Row Counts** — raw counts from the three custom DB tables:
- `pressocampus_resource posts` — posts in the `pressocampus_mem` CPT
- `resource_index rows` — rows in the resource index table
- `audit_log rows` — rows in the audit log table

**Last Activity** — timestamp of the most recent audit log entry.

### Example

```bash
wp pressocampus stats
```

```
== Memories per User ==
+-------+--------------+----------+-------------+
| user  | display_name | memories | soul_status |
+-------+--------------+----------+-------------+
| nick  | Nick Smith   | 247      | complete    |
+-------+--------------+----------+-------------+

== DB Table Row Counts ==
  pressocampus_resource posts : 249
  resource_index rows         : 249
  audit_log rows              : 1842

== Last Activity ==
  2025-03-19 14:22:35
```

---

## Global options

All commands support the standard WP-CLI global options (`--url`, `--path`, `--user`, etc.). On Multisite, use `--url=<site>` to target a specific sub-site before running commands.
