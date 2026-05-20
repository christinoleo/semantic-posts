# ADR-0004 — Crawler-based incremental indexing with brute-force cold start

**Status:** Accepted
**Date:** 2026-05-20

## Context

For each Indexable Post, SemanticPosts must compute and maintain a `_sp_related` list of the top-K (K=5 by default) most similar other Indexable Posts. The trivial implementation is brute force: every time a post is added or updated, compute cosine similarity against every other post (O(N) per update, O(N²) for a full pass).

At 5,000 posts:
- Brute force per update: ~5,000 cosine ops × 1,536 dimensions × constant ≈ 500ms–1s in PHP. Tolerable.
- Brute force global recompute (e.g., to catch drift): ~12.5M cosine ops, ~10–30 minutes. Painful.
- At 50,000 posts the global recompute becomes infeasible.

The product's position-as-moat is graph and data depth. The brute-force approach is what WPVDB and similar plugins do. To differentiate architecturally and to make the product scale to corpora where competitors break, a graph-based approach is justified — and is also the natural model for the problem.

## Decision

Maintain the corpus as a **Similarity Graph** (see CONTEXT.md) and update it incrementally via a crawler:

### Warm path (post save / update)

When post X's embedding changes:

1. **Candidate set construction** (no cosine yet):
   - X's previous `_sp_related` (its old outbound neighbors)
   - The `_sp_related` of those neighbors (neighbors-of-neighbors — exploits the small-world property of semantic spaces)
   - X's `_sp_inbound` (posts that currently include X in their top-K)
   - A small random sample of other posts (exploration, mitigates local optima)
   
   Typical size: 50–150 posts vs 5,000 for brute force.

2. **Score candidates:** compute cosine X ↔ each candidate. ~10–50 ms in PHP.

3. **Write X's new `_sp_related`:** top-K by score.

4. **Propagate to affected nodes:** for every P in {X's old outbound} ∪ {X's new outbound} ∪ {X's old inbound}, recompute cosine P ↔ X with the new X embedding, then update P's `_sp_related` and the inbound index accordingly.

5. **Update inbound index** (`_sp_inbound`) consistently with steps 3–4.

Worst case: ~25–200 cosine ops per save, regardless of corpus size. The cost is O(K²) where K is the typical neighborhood size, not O(N).

### Cold start (initial install or restore)

The crawler cannot bootstrap from an empty graph — candidate sets are empty.

For the initial indexing pass over N pre-existing posts:

1. **Brute force, batched.** Process posts in batches of 50 per WP-Cron tick. Compute embedding (if missing), then run brute-force pairwise to construct each post's initial top-K.
2. **Resumable.** Progress stored in a transient. PHP killed mid-batch resumes from the last completed post on the next cron tick. Never restarts from zero.
3. **Background.** User sees a progress bar in the admin; the rest of the site keeps working. Posts whose `_sp_related` hasn't been computed yet fall back to "most recent posts in same category" at render time.
4. **Time budget.** ~30 minutes for 5,000 posts at default rate limits.

### Verification (periodic drift check)

The crawler's incremental updates may accumulate approximation error: a candidate set could miss a globally-better neighbor that wasn't visible in any local neighborhood.

Mitigation: a weekly cron picks a random sample of M posts (default M=20), runs brute-force pairwise for those M against the whole corpus, and compares the result with what the graph claims. If mean rank disagreement exceeds a threshold, surface an admin notice "Recommendations may have drifted; consider triggering a full reindex."

Verification cost: M=20 × 5,000 cosine ops/week ≈ trivial.

## Consequences

**Positive:**
- Update cost is O(K²) per save, independent of corpus size. Scales to 50k+ posts where brute force breaks.
- No periodic global recompute needed. The cron only handles cold-start batches and verification samples.
- No "1-hour staleness window for related lists" — updates propagate to neighbors immediately in the same crawler pass.
- Naturally expresses the graph framing that justifies the product's architectural differentiation.
- Maintenance complexity (cron orchestration, priority queues, batch sizing) is *lower* than a brute-force + scheduled-recompute design.

**Negative:**
- Approximation. Candidate-set heuristics can miss truly-best neighbors when the neighborhood doesn't surface them. Mitigated by exploration sampling + verification pass, but never fully eliminated. Acceptable because semantic similarity is itself fuzzy.
- More state to maintain: `_sp_inbound` reverse index in addition to `_sp_related`.
- Cold start still requires brute force. The crawler does not eliminate the one-time bulk-index cost; it only makes ongoing maintenance cheap.
- Implementation cost: ~100–150 extra PHP LOC vs naïve brute force. Risk to the 1-week v1 budget — but the alternative (brute force + queue orchestration + periodic recompute) is comparable in code volume.

**Reversibility cost:** Switching to pure brute force later is trivial (delete the crawler, recompute globally on cron). Switching FROM brute force to crawler later is much harder (need to introduce `_sp_inbound`, refactor update paths). So building crawler first is the strictly safer direction.

## Future work (post-v1)

- HNSW-style multi-layer graph for very large corpora (>50k posts).
- Smarter exploration sampling: bias toward semantic regions underrepresented in current neighborhoods.
- Allow user-tunable K per post type (some content types benefit from K=3, others from K=10).
- Quality metric exposed in admin dashboard: "Verification pass found X% rank disagreement this week."
