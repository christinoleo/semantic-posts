# ADR-0006 — Recommendation ranking modes (configurable, three offered in v1)

**Status:** Accepted
**Date:** 2026-05-20

## Context

Given a Recommendable Post and its set of candidate neighbors (via the Similarity Graph), the plugin must produce an ordered list of K items. Different content sites benefit from different ordering strategies:

- **News / current-events blogs** value freshness. A reader who finished a 2026 article on AI policy is better served by a recent take than a 2021 deep-dive on the same topic.
- **Evergreen / knowledge-base sites** value pure relevance regardless of date. A 2018 explainer can still be the best match for a 2026 post.
- **Magazine / discovery-oriented sites** value coverage. A reader who finished a sourdough troubleshooting article is better served by 5 *different angles* (yeast, hydration, fermentation, milling, bread science) than 5 nearly-identical posts.

A single hard-coded ranking strategy serves one of these well and the other two poorly. Three approaches were considered:

- **(a) Pure cosine.** Simplest. Score = cosine(post, candidate).
- **(b) Cosine + recency.** Score = cosine × `exp(-age_days / decay)`. Fresh content surfaces higher.
- **(c) MMR — Maximal Marginal Relevance.** Item 1 = highest cosine. For each subsequent item: maximize `λ × cosine(post, candidate) − (1−λ) × max_cosine(candidate, already_picked)`. Produces a list whose items are similar to the source post but dissimilar to each other.

## Decision

Ship all three as a user-selectable mode in plugin settings. Default = **(a) pure cosine**.

Settings UI:

```
Recommendation style:
  ● Most relevant (default) — top-5 by pure semantic similarity.
  ○ Fresh-first — recent posts boosted higher in the list.
  ○ Diverse mix — top-5 spans different angles of similarity.
```

Each mode is implemented as a separate scoring function called at the "select top-K" step of the crawler. The embedding pipeline, similarity computation, storage, rendering, and Recommendation Source rules are all identical across modes.

The featured #1 position is **always the highest-cosine candidate**, in all three modes:
- In (a) and (b), this is the natural result.
- In (c), the algorithm explicitly seeds item 1 with the top cosine before applying MMR to items 2–K.

Default parameters:
- (b) decay constant: 180 days (a 6-month-old post has roughly half the score of a same-cosine new post).
- (c) λ = 0.7 (70% relevance, 30% diversity).

Both exposed via filters for power-user tuning: `semantic_posts_recency_decay`, `semantic_posts_mmr_lambda`.

## Consequences

**Positive:**
- Sites self-select the strategy that fits their content type.
- Featured #1 selection rule stays stable across modes — buyers asking "why is this one featured?" always get the same answer ("highest semantic match").
- Each scoring function is local — pipeline complexity does not grow.
- ~50 LOC total for all three modes vs ~10 LOC for one.

**Negative:**
- We are picking defaults blind. We do not know whether (a), (b), or (c) maximizes engagement on a given site type. Default (a) is the most defensible single guess.
- More user-facing settings = more support surface. Mitigated by good copy and a default that works.
- Mode-switching is instant (no reindex needed) but produces visibly different lists, which may confuse owners who don't recognize they changed the mode.
- We have no in-product way to validate which mode performed best. Engagement tracking is out of v1 scope; without it, mode selection is owner intuition.

**Reversibility cost:** Removing a mode later is trivial (delete setting option, drop scoring function). Adding a new mode (e.g., engagement-weighted in v1.5 once we ship click tracking) is also trivial — same plug-in point.

## Future work (post-v1)

- Engagement tracking — record clicks on rendered recommendations, expose per-mode CTR in the admin dashboard so owners can A/B test modes empirically rather than by intuition.
- Per-post-type mode override (e.g., use Fresh-first for `post` and Diverse mix for `page` on the same site).
- Auto-mode that picks based on site characteristics (corpus age distribution, post type mix). Not until there's data.
