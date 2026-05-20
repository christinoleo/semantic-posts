# ADR-0005 — No per-embedding model versioning; wipe-and-rebuild on model change

**Status:** Accepted
**Date:** 2026-05-20

## Context

Embeddings from different models live in different vector spaces. Cosine similarity between a vector produced by `text-embedding-3-small` and one produced by `text-embedding-3-large` is mathematically meaningless. Any indexing strategy that allows mixed-model state must therefore detect and refuse such cross-model comparisons.

A more elaborate design was considered: tag each `_sp_embedding` with the model that produced it, allow mixed-model states during gradual migration, refuse cross-model cosine in the crawler, and surface a per-post migration progress indicator. This adds ~60 bytes of overhead per post forever and meaningful code complexity for an event that may occur zero to two times in the product's lifetime.

The alternative is to treat the embedding model as a single global setting: changing it invalidates *all* embeddings and triggers the existing cold-start mechanism to rebuild from scratch.

## Decision

**No per-embedding model versioning.** All `_sp_embedding` values in a single installation are assumed to come from the model currently configured in plugin settings.

The mechanism:

- Plugin settings include `current_embedding_model` (string, default `openai/text-embedding-3-small`).
- On a settings save where `current_embedding_model` differs from its previous value:
  1. Confirm with the user: *"This will regenerate embeddings for all N posts, taking approximately X minutes and costing approximately $Y. Continue?"*
  2. On confirmation: wipe all `_sp_embedding`, `_sp_related`, `_sp_inbound`, `_sp_text_hash`, `_sp_dirty` postmeta.
  3. Trigger the existing cold-start path.
- The cold-start mechanism handles batching, resumability, progress reporting, and graceful fallback (category-based recommendations while embeddings are absent). No new code paths are introduced.

`_sp_embedding` storage carries no model identifier or version field.

## Consequences

**Positive:**
- Zero per-post overhead for model versioning. Storage stays as `{vec: base64}` only.
- No special code in the crawler for cross-model cosine refusal.
- Model migration reuses the cold-start path, which is already battle-tested.
- Schema stability — no future addition of a model-version column or key is needed when adding multi-provider support.

**Negative:**
- A model change wipes *all* recommendations at once. Until cold start completes, every post falls back to category-based suggestions. With 5,000 posts at default rate limits, this is a ~30-minute window of degraded recommendations.
- Cannot support A/B testing embedding models within a single installation. (Out of v1 scope anyway.)
- The user cannot "preview" a new model on a subset of posts before committing to a full migration. They commit to the full reindex up front.

**Reversibility cost:** Adding per-embedding model versioning later requires a schema migration: read every `_sp_embedding`, wrap with model metadata, write back. ~5,000 read-write ops at install time. Doable in a single plugin update without re-calling the embedding API. Medium-low cost.

## Future work (post-v1)

- Multi-provider Pro tier (Voyage, Cohere): same rule applies — switching provider wipes and reindexes. The settings field becomes `current_embedding_provider/model`.
- For users with very large corpora (50k+ posts) where a 5-hour reindex window is unacceptable, evaluate gradual migration (per-post tagging) as a Pro feature. Trade-off: code complexity vs. one specific user pain.
