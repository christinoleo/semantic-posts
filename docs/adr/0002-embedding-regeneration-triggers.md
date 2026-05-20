# ADR-0002 — Embedding regeneration triggers

**Status:** Accepted
**Date:** 2026-05-20

## Context

WordPress fires `save_post` on many events that are not actual content changes: autosaves (every ~60s while editing), revisions, quick-edit field changes (category, status, slug), and bulk operations. Regenerating an embedding on every fire would multiply OpenAI API spend by 50–100× per active post and may exhaust the user's quota during a single writing session.

Four trigger strategies were considered:

- **(a) Every `save_post`.** Simplest. Catastrophic for cost.
- **(b) First transition to `publish`/`future` only.** Cheapest. Misses all post-publication edits — common for evergreen content.
- **(c) Hash-diff of Indexable Text on `save_post`.** Compares current Indexable Text against the hash that produced the existing embedding (stored as postmeta `_sp_text_hash`). Regenerates only on real text change.
- **(d) Dirty-flag + scheduled batch.** Marks posts dirty on `save_post`; a periodic cron job processes dirty posts and regenerates only those with hash changes.

## Decision

**(c) + (d) combined, plus a guaranteed-fresh path on first publish.**

The trigger model:

1. **On `save_post`** — compute the current Indexable Text hash. If different from `_sp_text_hash`, mark the post dirty (postmeta `_sp_dirty = 1`). No API call yet.
2. **On hourly WP-Cron tick** — process up to N dirty posts: regenerate embedding, update `_sp_embedding` and `_sp_text_hash`, clear `_sp_dirty`, recompute affected `_sp_related` entries.
3. **On `transition_post_status` to `publish` for the first time** — bypass the dirty queue and regenerate immediately. Ensures new posts have related-posts data the moment they go live, not after the next cron tick.
4. **On `wp_trash_post`, password change, or transition away from `publish`** — remove `_sp_embedding`, `_sp_related`, `_sp_text_hash`. Invalidate any other post's `_sp_related` that pointed to this one.

## Consequences

**Positive:**
- Autosaves don't burn API calls — the hash only changes when the final content changes.
- A 30-save editing session generates 1 embedding call, not 30.
- First-publish path guarantees freshness on the user-visible "I just hit publish" moment.
- Edits to evergreen posts are picked up within an hour (cron tick).
- Edits to fields that don't affect Indexable Text (category, slug, featured image) never trigger regeneration.

**Negative:**
- Up to 1-hour staleness window for edits to already-published posts. Acceptable for related-posts use case.
- Two extra postmeta keys per post (`_sp_dirty`, `_sp_text_hash`) — minimal storage impact.
- Hourly cron must be performant: with 5000 posts and ~10 dirty per hour typical, processing is trivial; bulk-edit scenarios (50+ posts dirty at once) need batch-size limits to avoid runaway crons.

**Reversibility cost:** Changing the trigger strategy doesn't require re-indexing (existing embeddings stay valid). Just code change. Low.

## Future work (post-v1)

- Configurable cron frequency (15 min / 1h / 6h / daily) for sites with different freshness needs.
- Webhook trigger for headless / decoupled WordPress setups where save_post is not the canonical edit event.
- Smarter affected-set computation: when a post's embedding changes, only re-rank the K posts whose previous top-N included it, instead of full re-rank.
