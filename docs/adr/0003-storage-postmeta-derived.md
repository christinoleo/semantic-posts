# ADR-0003 — Storage location: postmeta, treating plugin data as derived

**Status:** Accepted
**Date:** 2026-05-20

## Context

Plugin state consists of four keys per Indexable Post:

- `_sp_embedding` (~6 KB binary-encoded vector)
- `_sp_related` (~50 bytes — top-N post IDs)
- `_sp_text_hash` (32 bytes)
- `_sp_dirty` (1 byte flag)

At 5,000 posts this is ~30 MB of additional row data, dominated by the embeddings.

Two storage locations were initially weighed: WordPress postmeta vs. a custom table. The custom-table case was driven primarily by backup-size concerns — at 30 MB, plugin data was visibly inflating site backups, and managed-host audits sometimes flag that pattern.

The decision pivoted when the question was re-examined from the other direction: **does this data need to be backed up at all?**

It does not. Every key is a deterministic function of canonical WordPress source data plus the embedding model. The embedding is `model.embed(indexable_text(post))`. The related list is `top_n(cosine(this_embedding, other_embeddings))`. The hash is `md5(indexable_text(post))`. None of these contain information not derivable from the source. They are build artifacts, not source data.

This reframes the storage decision: the size concern doesn't apply because the data should not be in backups in the first place. What remains is the standard WordPress storage question.

## Decision

**Store all plugin state in postmeta.** No custom tables.

Concretely:
- `_sp_embedding` — postmeta, base64-encoded `float32` little-endian binary
- `_sp_related` — postmeta, JSON-encoded `[{id, score}, ...]`
- `_sp_text_hash` — postmeta, hex string
- `_sp_dirty` — postmeta, `'1'` or absent

All keys prefixed `_sp_` (underscore prefix is the WordPress convention for non-displayed/internal postmeta).

The plugin treats this data as derived and documents that explicitly:
- README and FAQ instruct users to exclude `_sp_*` keys from backup plugin configurations.
- On install/restore, the indexer detects missing embeddings and regenerates in background. A 5,000-post site is fully reindexed in ~30 minutes at default rate limits.
- A graceful-restore admin banner appears if >5% of Recommendable Posts lack embeddings: "Reindexing in progress — related posts will populate shortly."
- A cosmetic filter `semantic_posts_exclude_from_backup` is provided for integration with backup plugins that respect such hooks.

## Consequences

**Positive:**
- Zero schema migration on install. Plugin activates, works, deactivates, leaves clean state.
- Standard WordPress data model — backup plugins, multisite, REST API endpoints, and `WP_Query` joins all work without special integration.
- DROP TABLE risk avoided. Uninstall deletes via `delete_post_meta` patterns, which is safer in shared-database environments.
- Compatibility with object-cache layers (Redis, Memcached) is automatic — `get_post_meta` is already cached.

**Negative:**
- `wp_postmeta` becomes larger by ~30 MB at 5k posts. Acceptable because the data should not be in backups (the dominant size pressure).
- Recompute-all operations (full re-rank pass) read from `wp_postmeta` without an index on `meta_value`. Acceptable at 5k posts (still <2 minutes); may need a custom table or index in v1.1 if user feedback shows pain at 20k+ posts.
- Cleanup on uninstall requires `DELETE FROM wp_postmeta WHERE meta_key LIKE '_sp_%'` (or equivalent `delete_post_meta` calls), which is slower than `DROP TABLE`. Mitigated by batching.

**Reversibility cost:** Moving from postmeta to a custom table later is a one-time migration: read postmeta rows, insert into table, delete postmeta rows. Doable in a single plugin update without API re-indexing because the embedding values themselves don't change. Medium cost, but not catastrophic.

## Future work (post-v1)

- If user feedback indicates pain at 20k+ posts (slow recompute, postmeta bloat affecting other plugins), evaluate moving `_sp_embedding` specifically to a custom table while keeping the others in postmeta.
- Add WP-CLI command `wp semantic-posts purge` for users who want to manually clear all plugin data for diagnostics or migration.
- Investigate whether `wp_postmeta` index on `meta_key` (which exists by default) plus a tighter `WHERE meta_key = '_sp_embedding'` filter is fast enough on shared hosts at 50k+ posts.
