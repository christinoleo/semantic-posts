# ADR-0008 — Phased cold start: brute-force bootstrap + graph-traversal kNN

**Status:** Accepted
**Date:** 2026-05-20
**Supersedes:** the "Cold start" subsection of [ADR-0004](0004-crawler-based-indexing.md) (warm crawler, verification pass, propagation rules in ADR-0004 are unchanged)

## Context

[Spike 0001](../spikes/0001-php-cosine-viability.md) measured pure-PHP cosine over base64-encoded `float32` postmeta vectors under Reference-environment constraints (1 vCPU, 256 MB `WP_MEMORY_LIMIT`, no opcache tuning). The headline finding for cold start was a memory wall, not a compute wall:

- A decoded PHP `SplFixedArray<float>` of 1536 floats costs ~24.5 KB (16 bytes/float × 1536), ~4× the base64 packed size (~6 KB).
- Holding all decoded vectors simultaneously to run all-pairs cosine costs ~122 MB at 5k posts and ~245 MB at 10k posts. The latter exceeds the Reference environment's `WP_MEMORY_LIMIT=256M`.
- Compute itself is fine — ~10 min total for 5k posts pairwise on the spike host; batched across cron ticks it fits comfortably under shared-host PHP request timeouts.

[ADR-0004](0004-crawler-based-indexing.md) specified cold start as "brute force, batched" — process 50 posts per cron tick, brute-force pairwise. This works up to ~5k posts but breaks at the upper end of the brief's "200–10,000 posts" target range because every tick must hold all already-indexed decoded vectors in memory.

The product cannot drop the upper range without invalidating positioning copy ("for blogs of all sizes," brief §Target user). Capping the v1 product at 5k posts would amount to redefining the audience, and the user explicitly chose to keep the brief's range and absorb the algorithmic cost (grilling session, 2026-05-20).

Four resolutions were considered:

- **(a) Cap positioning at 5k posts.** Rejected as a positioning change, not an algorithm change.
- **(b) Streaming brute-force.** Read inner-loop vectors from postmeta in chunks of M, hold only the current chunk + outer batch decoded. Memory bounded; ~80 min cold start for 10k posts (DB-I/O dominated), ~6 GB total postmeta reads across the run. Exact top-K. Acceptable but the slowest path.
- **(c) Random-sample brute-force.** Compare each new post against a random sample of M_sample existing posts instead of all. Memory bounded by sample size; approximate top-K. Faster than (b) but the random sample misses local structure that exists in the graph.
- **(d) Graph-traversal kNN with brute-force bootstrap.** Bootstrap the graph with brute-force pairwise on the first N_b posts; for each subsequent post, run a greedy graph walk from L random entry points to construct a high-quality candidate set, score the candidate set, take top-K. Reuses the same expansion + scoring + propagation logic as the warm crawler (ADR-0004); the only difference is the entry-point strategy.

Option (d) exploits the structure that already exists for free: the Similarity Graph is itself a near-optimal index for nearest-neighbour search in semantic-embedding space. Once the bootstrap produces a well-formed graph, neighbours-of-neighbours converge to true top-K in a small number of hops (small-world property of dense embedding spaces — empirically demonstrated by NSW/HNSW literature). It is also dramatically less code than (b) or (c) because the warm crawler already implements the expansion and propagation steps.

## Decision

Cold start runs in two phases:

### Phase 1 — Bootstrap (corpus size ≤ N_b)

Brute-force pairwise indexing. For each new post X processed during this phase:

1. Fetch X's embedding (OpenAI call, via the same code path as the warm crawler).
2. Decode all already-embedded posts' vectors (≤ N_b decoded vectors held in memory).
3. Compute cosine(X, P) for every already-embedded post P.
4. X's `_sp_related` = top-K by cosine.
5. Update `_sp_inbound` of selected neighbours.
6. For each affected neighbour, recompute their `_sp_related` if X displaces an existing entry.

Memory peak at end of Phase 1: ~N_b × 24.5 KB = ~5 MB at N_b = 200. Trivial.

### Phase 2 — Graph-traversal kNN (corpus size > N_b)

For each new post X processed during this phase:

1. Fetch X's embedding.
2. **Entry-point selection.** Pick L = 5 random Indexable Posts uniformly from the already-indexed set as walk entry points.
3. **Greedy walk with budget.** Initialise a max-heap of size K (= configured Recommendation List size, default 5) keyed on cosine to X. For each entry point E:
   - If E unvisited: load E's decoded embedding, compute cosine(X, E), insert (E, score) into the heap, mark E visited.
   - Expand from E: read E's `_sp_related` ∪ `_sp_inbound` IDs. For each unvisited neighbour N: load N's decoded embedding, compute cosine(X, N), update the heap, mark N visited.
   - Continue expansion best-first: the next node to expand is the highest-cosine unvisited node already seen in any neighbour list.
   - Stop when either: (a) the visit budget B_v = 300 nodes is exhausted, or (b) the highest-cosine unvisited candidate is below the heap's minimum (no improvement possible).
4. X's `_sp_related` = the top-K of the final heap.
5. Update `_sp_inbound` of selected neighbours and propagate as in the warm crawler.

**Postmeta batching.** Within a single walk, decoded vectors are loaded via `update_meta_cache('post', $batch_ids)` which preloads all `_sp_embedding` postmeta in one query per batch of IDs, then individual `get_post_meta` calls hit the WP object cache. This collapses the visit budget's worth of DB reads into ~2–4 batch queries per post processed.

**Memory peak per cron tick.** 50 outer (next batch of new posts) + B_v = 300 inner decoded vectors held during their respective walks. Decoded peak = (50 + 300) × 24.5 KB ≈ 8.6 MB. Adding WordPress core (~50 MB baseline) leaves ample headroom under 256 MB.

### Unified crawler

Phase 2's algorithm IS the warm crawler from ADR-0004 with a different entry-point strategy:

| Mode | Triggered by | Entry-point strategy |
|---|---|---|
| Insert (Phase 2 cold-start) | New post never had an embedding | L = 5 random Indexable Posts |
| Update | Existing post's `_sp_text_hash` changed (ADR-0002) | X's existing `_sp_related` ∪ `_sp_inbound` |

Expansion, scoring, top-K selection, propagation, and `_sp_inbound` maintenance are identical across modes. One crawler implementation, two entry strategies.

### Constants

| Parameter | Value | Rationale |
|---|---|---|
| N_b (bootstrap threshold) | **200 posts** | At N_b = 200, the bootstrap graph has 1000 outbound edges and average node degree ~10 once inbound is counted. Graph density is sufficient for Phase 2 traversal to converge in 2–3 hops with high probability. ~5 MB decoded memory peak. |
| B_v (visit budget per insert) | **300 nodes** | NSW literature reports 95–99% recall@5 for visit budgets in this range on similarly-sized graphs. Per-post compute = 300 × 47 µs ≈ 14 ms; per-tick (50 posts) ≈ 0.7 s of cosine + DB I/O. Comfortably under the 100 ms-per-update target from PRD §8 Q5. |
| L (entry points per insert) | **5** | Independent walks mitigate "all entry points landed in one cluster" pathology. Five is a common NSW default and adds negligible cost to the visit budget. |

All three are **hardcoded in v1.** No filter surface is exposed. If support traffic shows tuning need (corpora with extreme clustering, suggestion-quality complaints traced to Phase 2 approximation), filters can be added in a non-breaking update.

## Consequences

**Positive:**

- Memory bounded to ~8.6 MB peak regardless of corpus size. The Reference environment's `WP_MEMORY_LIMIT=256M` ceases to be a corpus-size limit.
- Cold start for 10k posts: ~12 minutes total compute, batched across cron ticks. For the Sarah persona (1,200 posts), cold start is all-Phase-1 brute force, ~34 s of compute and ~30 MB memory peak — even simpler than the Phase-2 path.
- Per-update compute target from PRD §8 Q5 (<100 ms on Reference env) is validated by the visit-budget arithmetic: ~14 ms compute + ~50 ms DB I/O = ~70 ms per insert, well under target.
- Code unification: the warm crawler and the Phase 2 cold-start path share expansion, scoring, top-K selection, propagation, and inbound maintenance. Bootstrap is the only additional path. Estimated +60 LOC over ADR-0004's design, mostly the greedy-walk priority queue.
- The Similarity Graph IS the index. No separate kNN data structure is built or maintained. Storage stays as `_sp_embedding` + `_sp_related` + `_sp_inbound` per ADR-0003.
- Brief's "200–10,000 posts" target range is honoured without positioning changes.

**Negative:**

- Phase 2 produces approximate top-K. Expected recall 95–99% based on NSW/HNSW literature; the remaining 1–5% may rank slightly below true neighbours.
- Approximation is healed gradually by two mechanisms already in scope: (i) every edit triggers the warm crawler, which uses the now-richer graph for the post's own neighbourhood and can pull in better neighbours via `_sp_inbound` propagation; (ii) the weekly verification pass (FR-5) samples M random posts, runs brute-force pairwise for them, and surfaces an admin notice if drift exceeds threshold. Together these converge the graph toward the brute-force optimum within weeks of normal site operation.
- Graph connectivity is a soft assumption. A pathological corpus where Phase 1's bootstrap produces a disconnected graph (e.g., 200 posts split into two semantically isolated topics with no bridges) may degrade Phase 2 quality. L = 5 random entry points provides redundancy; in practice OpenAI's `text-embedding-3-small` produces dense enough vectors that disconnection is theoretical for the brief's target content domain (editorial blog content).
- One additional concept ("Phase 1 vs Phase 2 cold start") to explain in admin observability copy if users ask about indexing behaviour. Acceptable; the observability panel already exposes progress percentage, which is the only thing most users care about.
- N_b = 200 is a magic number. Decreasing it speeds Phase 1 at the cost of a sparser bootstrap graph (more risk of bad early Phase 2 walks); increasing it slows Phase 1 at the cost of more memory peak. The chosen value is defensible from first principles but unvalidated empirically until launch traffic exists.

**Reversibility cost:**

- Reverting to brute-force-only cold start (per ADR-0004's original design): trivial code change — delete the graph-walk entry strategy, fall through to brute force in all phases. The memory wall reappears at ~5k posts, capping the product's effective ceiling. Existing data is unaffected.
- Migrating to HNSW-style multi-layer graph (post-v1 future work): meaningful additional work — layer index built on top of the existing graph, layer-aware walk. The current design is a subset of HNSW (single layer); HNSW would extend rather than replace it. Existing `_sp_embedding`, `_sp_related`, `_sp_inbound` postmeta keep their meaning.

## Future work (post-v1)

- Expose `semantic_posts_cold_start_visit_budget` and `semantic_posts_cold_start_entry_points` filters if support requests surface tuning needs.
- Telemetry: record actual visit count per insert (vs the 300 budget) and convergence steps. Informs whether 300 is too high (waste) or too low (truncating walks before convergence).
- Multi-source beam search instead of greedy walk — may improve recall on dense clusters at the cost of a more complex expansion loop.
- HNSW-style multi-layer index for corpora beyond the brief's 10k positioning range. Out of v1; relevant only if Pro tier ships and Pro user demand surfaces for very large blogs (50k+ posts).
- Empirical validation of N_b: at launch, instrument cold-start to log whether Phase 2 walks find candidates not in Phase 1's brute-force top-K within the visit budget. If they consistently do, raise N_b; if walks converge fast, lower it.
