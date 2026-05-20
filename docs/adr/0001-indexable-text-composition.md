# ADR-0001 — Indexable Text composition

**Status:** Accepted
**Date:** 2026-05-20

## Context

For each Indexable Post, SemanticPosts sends a single text string to the embedding API. The choice of what goes into that string determines the quality of similarity matching forever — changing this composition later requires re-indexing every post in the corpus, which costs API calls, time, and the user's quota.

Five compositions were considered:

- **(a) Title only.** Cheap, but ~10 words per post is too sparse to produce a discriminating vector.
- **(b) Title + raw post_content.** Includes HTML, shortcodes, and rendered Gutenberg block markup. The markup noise pollutes the vector — `<figure class="wp-block-image">` and similar produce tokens that carry no topical signal.
- **(c) Title + cleaned post_content.** HTML stripped, shortcodes left as raw `[shortcode]` (not rendered). Cleaner signal than (b) without the cost of running `do_shortcode()` (which can trigger HTTP calls, form rendering, etc.).
- **(d) Title + manual post_excerpt + cleaned post_content.** Adds the author-curated summary when present. Auto-generated excerpts are skipped because they are redundant with the content.
- **(e) Any of the above with the title repeated 2–3× at the start of the string.** SEO-aware authors invest heavy semantic content in titles; repetition gives the title proportional weight in the resulting vector.

## Decision

Use **(d) + (e)**: title (repeated 3×) + manual excerpt (when present) + cleaned post_content, truncated to ~6500 words when longer.

Concretely, the input string is:

```
{title}\n\n{title}\n\n{title}\n\n{excerpt_if_manual}\n\n{stripped_content_truncated}
```

- HTML is stripped via `wp_strip_all_tags()`.
- Shortcodes are NOT rendered. They remain as raw `[shortcode_name]` tokens in the input.
- `post_excerpt` is included only when it differs from the auto-generated excerpt (a manual override).
- Content is truncated to ~6500 words to stay safely under the 8191-token limit of `text-embedding-3-small`.

## Consequences

**Positive:**
- Title-heavy weighting matches how SEO-aware authors invest in titles.
- Manual excerpts (when present) act as author-curated topic anchors.
- HTML stripping keeps the vector clean of layout noise.
- Skipping `do_shortcode()` keeps indexing fast and side-effect-free.

**Negative:**
- Long-form content (>6500 words) loses its conclusion in the embedding. Documented limitation; multi-chunk embedding with averaging is a future optimization.
- Posts whose shortcodes wrap the actual content body (rare but exists — e.g., `[content-protector]...[/content-protector]`) will have weakened embeddings since the wrapped content survives but the unrendered shortcode token adds noise.
- Title repetition is a magic number (3) that may need tuning. Future evaluation may surface a better ratio.

**Reversibility cost:** Changing this composition forces a full re-index of all Indexable Posts. At 5000 posts × $0.0001 per embedding ≈ $0.50 plus indexing time. Not catastrophic, but not free either.

## Future work (post-v1)

- Empirically validate title repetition factor (test 1×, 2×, 3×, 5× on a corpus).
- Multi-chunk embedding for long-form (>6500 words) with vector averaging.
- Optional `do_shortcode()` rendering with a host-safety guard (timeout, no-HTTP filter).
- Per-CPT extraction rules (e.g., for `product`: include attributes; for `recipe`: include ingredient list).
