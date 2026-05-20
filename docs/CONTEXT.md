# SemanticPosts — Domain Glossary

Canonical terms for the SemanticPosts project. Resolved during grilling sessions; updated inline as decisions crystallize.

This file is a glossary only — not a spec, not a scratch pad, not a place for implementation details.

---

## Purpose

SemanticPosts computes semantic similarity between long-form text content items in a single WordPress site and exposes each item's top-N most similar items as a front-end "read next" list.

**Optimized for:** editorial content sites — blogs, knowledge bases, recipes, reviews, course lessons. Anywhere the unit of content is a single-author, coherent, mostly-text piece a reader consumes in one sitting.

**Explicitly out of scope:** e-commerce product recommendation, forum/discussion discovery, listings/classifieds cross-linking. Those use cases require signals (price, stock, recency-of-activity, dates) that SemanticPosts does not consume.

**Corpus floor:** the product's value scales with corpus size. Below ~50 indexable items the semantic signal is too sparse to surface useful recommendations. Marketing and onboarding should set this expectation; the plugin does not refuse to run below the floor but may surface a warning.

---

## Entities

### Indexable Post

A WordPress content item eligible to have an embedding generated.

**Criteria:**
- `post_type` ∈ `settings.indexable_post_types` (default: `['post']`; UI lets the site owner opt in any *public* post type registered on the site)
- `post_status` ∈ `{'publish', 'future'}` — scheduled posts are pre-indexed so publication becomes instant
- `post_password = ''` — password-protected content is excluded to prevent leakage in recommendations
- Not in `trash`

### Recommendable Post

An Indexable Post that is currently allowed to appear in a front-end related-posts list. Strict subset of Indexable.

**Additional criterion beyond Indexable:**
- `post_status = 'publish'` (not `future`)

When a post leaves Recommendable status (moved to trash, password added, status changes away from `publish`), its embedding and `_sp_related` entry are removed via the matching status-transition hooks. No stale data left in the database.

**Multilingual sites:** If Polylang (`function_exists('pll_get_post_language')`) or WPML (`function_exists('icl_object_id')`) is active on the site, Recommendable Posts are additionally filtered to **the same language as the source post being rendered**. This prevents the PT version of a reader's page from surfacing EN recommendations. Filter `semantic_posts_disable_language_filter` overrides this when explicitly disabled.

### Indexable Text

The canonical text representation of an Indexable Post used to compute its embedding. Two posts are "semantically similar" if and only if their Indexable Texts produce nearby embedding vectors.

Composed from `post_title`, `post_excerpt` (when manually authored), and `post_content` with HTML stripped. The exact composition recipe — including the deliberate over-weighting of `post_title` — is recorded in [ADR-0001](adr/0001-indexable-text-composition.md).

Changes to a post's Indexable Text invalidate its embedding. Changes to other fields (slug, categories, featured image, comment status, etc.) do not.

---

## Structures

### Similarity Graph

The conceptual data structure SemanticPosts maintains across the corpus.

- **Nodes:** Indexable Posts.
- **Outbound edges per node:** top-K most similar other Indexable Posts, stored as `_sp_related` on the source node. Edge weight is cosine similarity.
- **Inbound edges per node:** reverse index — the set of nodes that currently include this node in their outbound top-K. Stored as `_sp_inbound`. Used by the crawler to propagate updates when a node's embedding changes.

The graph is intentionally sparse (K outbound edges per node, typically 5) and asymmetric (A in B's top-K does not imply B in A's top-K).

The indexing strategy maintains this graph incrementally; it is never globally recomputed in normal operation. See [ADR-0004](adr/0004-crawler-based-indexing.md) for the warm crawler and [ADR-0008](adr/0008-phased-cold-start-knn.md) for the phased cold-start design.

---

## Properties

### Ranking Mode

The strategy used to order the candidates in a Recommendation List. Three modes ship in v1, owner-selectable in settings:

- **Most relevant** (default) — pure cosine ordering.
- **Fresh-first** — cosine weighted by `exp(-age_days / decay)`. Boosts recent content.
- **Diverse mix** — MMR (Maximal Marginal Relevance). Items 2–K balance relevance to the source post against dissimilarity to already-picked items.

In all three modes, the featured #1 position is the highest-cosine candidate. Items 2–K are where the modes differ. See [ADR-0006](adr/0006-recommendation-ranking-modes.md).

### Recommendation List

The set of items rendered for a single Recommendable Post.

**Default behavior:**
- Size = exactly K items (K configurable in settings, default 5, range 3–10).
- Contents = top-K outbound edges from the Similarity Graph by cosine score.
- If the corpus has fewer than K total candidates, the list shrinks to match — no padding with unrelated items.

**Opt-in "quality-bounded" behavior** (checkbox in advanced settings):
- Settings expose `min_items` (default 3), `max_items` (default K), and `score_threshold` (default cosine 0.3).
- The list contains between `min_items` and `max_items` entries; entries below `score_threshold` are dropped.
- The owner accepts that the list may render with different sizes on different posts in exchange for higher per-item quality.

**Rationale for the default:** size predictability eases mental models for site owners ("why does this post show 2 but that one shows 5?" is a confusing question to receive in support). The opt-in flag exists for owners who explicitly care about quality more than consistency.

### Recommendation Source

Every rendered related-posts widget has a *source* — one of:

- **`semantic`** — the items come from the Similarity Graph. The intended product behavior.
- **`category-fallback`** — the items come from "most recent posts in the same category as the current post," used silently when the semantic source is unavailable for this post.
- **`none`** — the widget renders nothing. Reserved for cases where even category fallback yields zero results (rare).

The product rule: **the reader always sees the best available source for the current post**, with no technical messaging exposed. The admin sees full transparency about which source is active site-wide and per-post via the admin dashboard.

Source resolution by state:

| State | Reader-visible | Admin-visible |
|---|---|---|
| No API key configured | `category-fallback` for every post | Banner: "Configure your API key to enable semantic recommendations" |
| Cold start in progress | `semantic` for already-indexed posts, `category-fallback` for not-yet-indexed | Progress bar: "X / Y posts indexed" |
| Specific post in dead-letter (3 retries failed) | `category-fallback` for that post | Notice with retry button on the post |
| Steady state | `semantic` everywhere | Dashboard shows healthy |

The rendered HTML carries `data-sp-source="..."` so admins can inspect the active source per page during diagnostics without exposing the source to readers.

### Derived Data

All data the plugin writes (`_sp_embedding`, `_sp_related`, `_sp_text_hash`, `_sp_dirty`) is **derivable from WordPress source data** (posts + plugin settings + embedding model). It contains no information that cannot be regenerated from the canonical inputs.

Consequence: this data is **not backup-worthy**. The product treats it like build artifacts — useful in production, regenerable from source, excluded from backups in the recommended configuration. After a restore from a backup that omitted plugin data, the indexer regenerates everything in the background; user-visible recommendations populate within ~30 minutes for a 5,000-post site.

This property dictates the storage location decision in [ADR-0003](adr/0003-storage-postmeta-derived.md).
