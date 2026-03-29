# Pressocampus roadmap (maintenance notes)

Internal notes for future improvements. Not a public commitment timeline.

## Search and deduplication

- **similar_text() dedup** (`tool_remember`): Cheap server-side check, but the metric is string-edit distance, not semantics. At scale, consider trigram similarity or TF-IDF in SQL (no external services required).
- **Search path**: Memory search now uses FULLTEXT on the index excerpt plus title `LIKE` (no full `post_content` scan). A future migration could add a dedicated title column to `pressocampus_resource_index` for unified FULLTEXT ranking.

## Rate limiting

- **Object cache**: A persistent object cache (Redis/Memcached) is strongly recommended. Without it, rate limits use file counters in `wp-content/cache/pressocampus-rate/`, with transients as a last resort.

## Product / marketing

- The **Soul** concept is the differentiator; readme and positioning already lean into it. Renaming the plugin is a separate product decision.

## PHP compatibility

- Minimum PHP is **8.1** to match typical WordPress hosts. Re-audit if you adopt 8.2+-only syntax later.
