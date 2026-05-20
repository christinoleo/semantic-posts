# SemanticPosts

> Related posts that are actually related, without slowing your site down.

A WordPress plugin that uses semantic embeddings to surface "next reads" with substantially better relevance than category matching — while staying lighter on page-load cost than every existing alternative.

**Status:** Planning complete. Implementation in progress.

---

## Why this exists

WordPress site owners are stuck between two bad options for related posts:

- **Accurate but slow:** YARPP, Contextual Related Posts. Compute relevance per pageview via brute-force MySQL queries. Several are still on managed-host disallow lists (WP Engine bans 4 of them as of May 2026).
- **Fast but dumb:** Jetpack Related Posts, Same Category Posts. Category matching surfaces the same 3 recent posts on every page.

SemanticPosts is the third option: **precompute once at index time, serve from cache**.

## How it works

1. When you save a post, the plugin generates an embedding via OpenAI (background, rate-limited).
2. A Similarity Graph maintains the top-N most-related posts per post in `postmeta`, updated incrementally on every save.
3. On every page view, related posts come from cached `postmeta` — **2 indexed queries, zero HTTP**.

Result: page-load cost lower than category-matching plugins, recommendation quality higher than brute-force semantic alternatives, and the plugin doesn't trip any managed-host audit.

**Self-contained.** No external vector database. No Qdrant, no Pinecone, no Supabase, no Typesense. Works on commodity WordPress hosting with nothing beyond an OpenAI API key.

## Architecture summary

The plugin is split into two paths separated by a hard wall:

- **Render path** (every page view): allowed exactly `get_post_meta` + `WP_Query`. Zero HTTP. <1MB memory delta. <5ms TTFB delta. Page-cache-compatible (deterministic HTML per post).
- **Indexing path** (background only): WP-Cron or WP-CLI. All OpenAI calls happen here. Memory-bounded (~8.6MB peak per tick regardless of corpus size). Resumable across cron-tick deaths.

Cold-start indexing uses a phased approach: brute-force pairwise for the first 200 posts (Phase 1 bootstrap), then graph-traversal kNN walk for posts beyond (Phase 2, visit budget 300, L=5 random entry points). See [ADR-0008](docs/adr/0008-phased-cold-start-knn.md).

Pure-PHP cosine compute viability was measured before architecture froze: ~10 min for 5,000-post pairwise on a 256MB shared host, ~39MB of base64-encoded `float32` postmeta storage. See [spike 0001](docs/spikes/0001-php-cosine-viability.md).

## Project structure

```
docs/
├── CONTEXT.md                          # Domain glossary
├── adr/0001..0008-*.md                 # Architecture Decision Records (locked)
└── spikes/0001-php-cosine-viability.md # Pure-PHP cosine compute viability spike

_bmad-output/planning-artifacts/        # Planning artifacts
├── project-brief.md                    # Positioning, market, kill criteria
├── prds/prd-semanticPosts-2026-05-20/  # PRD + addendum + decision log
├── architecture.md                     # Architecture, patterns, EV registry
└── epics.md                            # 4 epics, 37 stories, FR coverage

spike/                                  # PHP cosine spike (Docker fixture)
```

## Implementation tracking

Work is broken into 18 tracer-bullet issues, each cutting end-to-end through the stack. See [open issues](https://github.com/christinoleo/semantic-posts/issues).

- **Epic 1** (Foundation & First-Render Path) — #1 through #4
- **Epic 2** (Semantic Indexing Pipeline) — #5 through #12
- **Epic 3** (Operational Surface) — #13 through #16
- **Epic 4** (Launch Readiness) — #17 through #18

Labels: `afk` (implementable end-to-end without human input) / `hitl` (requires manual step — review, evaluation, or external action). Epic labels mark which epic each issue belongs to.

## Scope and discipline

This is a **disposable 1-week MVP** with an explicit 90-day kill criterion per the [project brief](_bmad-output/planning-artifacts/project-brief.md):

- **Day 90:** if active installs < 500, the project ends.
- **Day 90:** if active installs ≥ 1,000, a Pro tier ships.
- **Anything past Day 180** is upside, not commitment.

The repo is public for transparency (host audit story, marketing-site backing) — not because the project actively solicits contributions. Issues and PRs are welcome but managed at solo-maintainer cadence.

## Distribution

Free on WordPress.org with the user's own OpenAI API key. Pro tier is deferred and ships only if Day-90 milestones hit.

## License

[GPL-2.0-or-later](LICENSE) — required for WordPress.org plugin distribution. Pro tier inherits the same license per WordPress plugin freemium convention (protection comes from license-key-gated updates and hosted features, not from license terms).
