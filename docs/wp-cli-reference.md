# WP-CLI Reference

Pressocampus includes a comprehensive WP-CLI command set for power users, DevOps, and automated workflows. All commands use the `wp pressocampus` namespace.

---

## Installation check

```bash
wp pressocampus --help
```

If Pressocampus is active, you'll see the full command list. If the plugin isn't installed or active, WP-CLI will report the command as unknown.

---

## `wp pressocampus list`

List memories for a user.

### Usage

```bash
wp pressocampus list [--user=<user>] [--group=<group>] [--format=<format>]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--user=<user>` | Current user | Username, email, or ID |
| `--group=<group>` | All groups | Filter by group name |
| `--format=<format>` | `table` | `table`, `json`, `csv`, `ids` |

### Examples

```bash
# List all memories (table format)
wp pressocampus list

# List all memories for a specific user
wp pressocampus list --user=nick

# List memories in the "work" group as JSON
wp pressocampus list --group=work --format=json

# Get just the URIs
wp pressocampus list --format=ids
```

### Output (table)

```
+------------------------------------------+---------------------------------+-------+----------+
| uri                                      | name                            | group | priority |
+------------------------------------------+---------------------------------+-------+----------+
| pressocampus://yoursite.com/soul         | Soul                            | soul  | critical |
| pressocampus://yoursite.com/memory/abc1  | Prefers TypeScript              | tech  | important|
+------------------------------------------+---------------------------------+-------+----------+
```

---

## `wp pressocampus get`

Retrieve a single memory by URI.

### Usage

```bash
wp pressocampus get <uri> [--format=<format>]
```

### Examples

```bash
# Get the soul
wp pressocampus get pressocampus://yoursite.com/soul

# Get a specific memory as JSON
wp pressocampus get pressocampus://yoursite.com/memory/abc12345 --format=json
```

### Output

Full memory content is printed to stdout. With `--format=json`, you get the complete metadata object.

---

## `wp pressocampus delete`

Delete a memory by URI.

### Usage

```bash
wp pressocampus delete <uri> [--yes]
```

### Notes

- Protected memories (soul, index) cannot be deleted with this command — they're protected even at the CLI level to prevent accidents
- Without `--yes`, you'll be prompted to confirm
- Deletion is irreversible

### Examples

```bash
# Delete with confirmation prompt
wp pressocampus delete pressocampus://yoursite.com/memory/abc12345

# Delete without prompting
wp pressocampus delete pressocampus://yoursite.com/memory/abc12345 --yes
```

---

## `wp pressocampus export`

Export memories to a file or folder.

### Usage

```bash
wp pressocampus export [--user=<user>] [--format=<format>] [--output=<path>]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--user=<user>` | Current user | User to export |
| `--format=<format>` | `json` | `json` or `markdown-folder` |
| `--output=<path>` | stdout / `./brain/` | Output file or directory |

### Formats

**`json`** — Single `brain.json` file with all metadata and content. Best for backup and programmatic processing.

**`markdown-folder`** — A directory of `.md` files, one per memory. The soul is exported as `SOUL.md`. Best for reading, version control, or processing with other tools.

### Examples

```bash
# Export to JSON (stdout)
wp pressocampus export

# Export to a JSON file
wp pressocampus export --format=json --output=brain-backup.json

# Export to a folder of Markdown files
wp pressocampus export --format=markdown-folder --output=./brain/

# Export a specific user's memories
wp pressocampus export --user=alice --output=alice-brain.json
```

---

## `wp pressocampus import`

Import memories from a previous export.

### Usage

```bash
wp pressocampus import --file=<path> [--user=<user>] [--yes] [--replace]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--file=<path>` | Required | Path to `brain.json` or a Markdown folder |
| `--user=<user>` | Current user | User to import into |
| `--yes` | false | Skip confirmation prompt |
| `--replace` | false | Overwrite memories with matching URIs |

### Notes

- By default, memories with URIs that already exist are **skipped** (not overwritten)
- Use `--replace` to overwrite existing memories
- The soul is imported and merged with the existing soul if `--replace` is not set

### Examples

```bash
# Import from JSON, preview first (dry run is the default without --yes)
wp pressocampus import --file=brain.json

# Import, overwrite existing memories
wp pressocampus import --file=brain.json --yes --replace

# Import a Markdown folder into a specific user
wp pressocampus import --file=./brain/ --user=bob --yes
```

---

## `wp pressocampus migrate-domain`

Update all memory URIs when your site's domain changes.

### Usage

```bash
wp pressocampus migrate-domain --from=<old-domain> --to=<new-domain> [--dry-run] [--yes]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--from=<domain>` | Required | Old hostname (e.g., `old.com`) |
| `--to=<domain>` | Required | New hostname (e.g., `new.com`) |
| `--dry-run` | false | Preview changes without applying them |
| `--yes` | false | Skip confirmation |

### Notes

- Updates `_pressocampus_uri` meta for all memory posts
- Updates all `_pressocampus_related` references
- Updates the soul and index URIs
- Run this immediately after changing your domain to keep URIs consistent

### Examples

```bash
# Preview the migration
wp pressocampus migrate-domain --from=old.com --to=new.com --dry-run

# Apply the migration
wp pressocampus migrate-domain --from=old.com --to=new.com --yes
```

---

## `wp pressocampus flush-cache`

Clear the Pressocampus object cache.

### Usage

```bash
wp pressocampus flush-cache
```

Use this after making manual database changes or if you suspect stale cache data. Normal operation should never require this.

---

## `wp pressocampus audit`

View the audit log.

### Usage

```bash
wp pressocampus audit [--user=<user>] [--action=<action>] [--limit=<n>] [--format=<format>]
```

### Options

| Option | Default | Description |
|--------|---------|-------------|
| `--user=<user>` | All users | Filter by user |
| `--action=<action>` | All actions | `remember`, `forget`, `update`, `search`, `soul_update` |
| `--limit=<n>` | 20 | Number of entries to return |
| `--format=<format>` | `table` | `table`, `json`, `csv` |

### Examples

```bash
# Last 20 audit entries
wp pressocampus audit

# All "forget" actions
wp pressocampus audit --action=forget

# Last 100 entries for a user, as JSON
wp pressocampus audit --user=alice --limit=100 --format=json
```

---

## `wp pressocampus stats`

Display memory statistics.

### Usage

```bash
wp pressocampus stats [--user=<user>]
```

### Output

```
User: nick
Total memories: 247
Soul: exists (last updated 3 days ago)
Groups: work (89), personal (62), technical (54), projects (42)
Priority breakdown: critical (12), important (67), normal (145), low (23)
Oldest memory: 2024-08-15
Largest memory: 4.2KB
```
