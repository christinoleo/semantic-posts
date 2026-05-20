---
title: SemanticPosts
status: final
created: 2026-05-20
updated: 2026-05-20
---

# PRD: SemanticPosts
*Working title — confirm before WP.org submission. Plugin slug `semantic-posts` assumed available; [ASSUMPTION: slug not yet verified on WordPress.org].*

## 0. Document Purpose

This PRD is the planning contract between the product owner (Leo) and the implementation session running in parallel. It builds on `project-brief.md` (same folder); it does not duplicate the brief's strategy, market analysis, or research bibliography — those remain canonical there and in `addendum.md`. Downstream artifacts (architecture, epics/stories, sprint plan) reference Glossary terms verbatim and FR/SM/UJ IDs from this document.

**Domain glossary and architectural decisions** live in `../../docs/CONTEXT.md` and `../../docs/adr/`. CONTEXT.md is the canonical source of truth for domain terms (Indexable Post, Recommendable Post, Indexable Text, Similarity Graph, Recommendation List, Ranking Mode, Recommendation Source, Derived Data). ADRs 0001–0007 record the hard-to-reverse architectural decisions made during the grilling session on 2026-05-20. This PRD references those documents rather than reproducing their content; where the PRD diverged from them during earlier drafting, the CONTEXT.md / ADR text is authoritative.

Vocabulary is Glossary-anchored. Features are grouped, FRs nested under each. Assumptions are tagged inline `[ASSUMPTION]` and indexed in §9. Detail that doesn't earn a place in the main narrative — rejected alternatives, technical-how, full competitive teardown, host pre-approval scripts, deferred-tier specs, research citations, marketing-distribution tactics — is in `addendum.md`.

## 1. Vision

**SemanticPosts does one thing: related posts that are actually related, without slowing your site down.**

WordPress site owners have been forced to pick between accurate-but-slow plugins (banned by several managed hosts) and fast-but-dumb category-matching plugins (visibly poor suggestions). SemanticPosts is the first consumer-positioned plugin in a third bucket: the recommendation engine runs once at publish time, the front-end serves cached results from `postmeta` with two indexed queries, and nothing expensive happens during a pageview. The pitch to the buyer is the outcome — recommendations that work, that don't slow the site down, that don't trip the host's audit. The underlying machinery (vector embeddings, semantic similarity, async indexing) is credibility detail for the FAQ, not the headline.

The plugin is **self-contained**: no external vector database, no Qdrant, no Pinecone, no Supabase, no Typesense. The buyer brings one OpenAI API key; everything else lives inside their existing WordPress install on commodity managed hosting. Distribution rides WordPress.org's organic install funnel and SEO content that targets the audience SemanticPosts is built for: site owners whose current related-posts plugin is banned by their host, or whose category-matching alternative is visibly weak. Discipline is a 90-day kill criterion: if the plugin hasn't earned 500 active installs and 20 reviews by day 90, the project ends.

## 2. Target User

### 2.1 Primary Persona

**Sarah, the niche-blog site owner with a content library that has outgrown category-matching.** 200–10,000 posts. Has already noticed reader drop-off after the first article and is dissatisfied with either (a) the visible quality of Jetpack/Same Category suggestions or (b) the page-load cost or host-incompatibility of YARPP/Contextual Related Posts. Technically aware enough to install a plugin, paste an API key, and read a setup wizard. Not a developer. Cares about reader engagement and page speed in roughly equal measure. Detailed persona sketch in addendum §D.

Sarah is the load-bearing persona — she covers both first-run (UJ-1) and steady-state (UJ-2 publish, UJ-4 audit) flows. There is no second user-side persona; the secondary stakeholder is the managed-host support engineer (UJ-5), who is effectively a second buyer whose approval gates distribution.

Secondary: agency operators running multiple client sites on managed hosting, who need a related-posts plugin that doesn't trigger their host's audit list. They follow Sarah's path — if her install works, theirs does too.

### 2.2 Jobs To Be Done

- **Keep readers reading.** Convert one-article visits into rabbit-hole sessions by suggesting articles the reader will actually want next.
- **Don't slow the site down.** Page-load cost of related-posts must be lower than category-matching alternatives, not higher.
- **Don't trip the host's audit.** Plugin must survive managed-host (WP Engine, Kinsta, Pressable, Pantheon, GoDaddy Managed) performance review without manual conversations.
- **Install and see value in one sitting.** New user goes from install to first related-posts appearing on live content in under five minutes.

### 2.3 Non-Users (v1)

- **Developers building search experiences.** WPVDB and AI Engine cover that surface; this plugin is positioned as a consumer related-posts engine, not a search/RAG infrastructure layer.
- **Sites whose primary need is cross-language semantic relatedness.** v1 supports same-language recommendations on Polylang/WPML-enabled sites via defensive filtering; cross-language matching is not a marketed feature.
- **WooCommerce store operators looking for product recommendations.** Owners can opt the `product` post type into indexing in settings, but the plugin does not consume commerce signals (price, stock, attributes). Dedicated commerce recommendation engines (Boost AI, Searchanise, Recom AI) are better fits.
- **Sites under 50 posts.** With too little content, the related-posts experience itself is weak regardless of plugin choice.

### 2.4 Key User Journeys

- **UJ-1. Sarah installs the plugin and sees her first related posts within five minutes.**
  Sarah runs a 1,200-post niche blog on Kinsta. She installs SemanticPosts from WordPress.org, lands on a setup wizard, pastes her OpenAI API key, and sees a cost estimate: *"Indexing your 1,247 posts will cost approximately $0.02 via OpenAI."* She clicks **Start indexing**. A progress bar advances through the corpus in batches. Within two minutes the wizard says "Indexing complete." She visits her most recent post on the front-end and sees the related-posts section already rendering at the end of the article: one featured card on top, four smaller cards below. **Climax:** the featured card is a post from a different category that's genuinely thematically related — something her old Same Category plugin never surfaced. **Resolution:** she leaves the default settings on, closes the tab, and goes back to writing.

- **UJ-2. Sarah publishes a new article; related posts populate without her attention.**
  Sarah drafts a new post, clicks Publish. The `transition_post_status` handler detects first-publish and immediately enqueues embedding generation (bypassing the hourly dirty queue). Within ~60 seconds, WP-Cron fetches the embedding from OpenAI and the Crawler updates the Similarity Graph for the new post — computing its `_sp_related` and propagating to affected neighbors (typically ~25–200 cosine ops, sub-second). The next time anyone visits Sarah's new post, related-posts render with no per-request computation. **Edge case:** if the new post is viewed *before* the crawler completes (rare, <60s window), the section renders the post's category-fallback recommendations silently — never empty, never stale. See CONTEXT.md §Recommendation Source.

- **UJ-3. A reader scrolls to the end of an article and clicks into a second one.**
  Reader finishes an article on Sarah's site. End-of-article section appears with a featured "next article" card (large thumbnail, larger title, one-line excerpt) and four smaller related items below. The featured card is a post the reader would not have found via category navigation. **Climax:** they click. **Resolution:** the next article loads, with its own related-posts section at the bottom. The session continues. Realizes UJ-1's payoff for Sarah.

- **UJ-4. The site owner audits indexing health from the admin panel.**
  Two weeks after install, Sarah opens the plugin's admin page. A single-screen observability panel shows: *"Last 24h — 8 embedding API calls, 0 page-render API calls, 0 errors, 6MB peak memory."* She does not need to contact support to know the plugin is behaving. **Resolution:** she closes the tab.

- **UJ-5. A managed-host support engineer audits the plugin during a routine review.**
  Engineer at a managed host opens the plugin's architecture diagram (linked from the plugin's WP.org listing and the marketing site), sees one paragraph explaining `save_post → hash-diff dirty marker → hourly WP-Cron → postmeta cache → 2 indexed queries per pageview`, and verifies the claim in 30 seconds via the live observability panel inside a test install. **Climax:** the plugin moves to the host's "compatible" list. **Resolution:** that signal becomes copy on the plugin's WP.org listing.

## 3. Glossary

The canonical domain glossary is in `../../docs/CONTEXT.md`. PRD-local terms below cross-reference CONTEXT.md entries verbatim; do not redefine them here without updating CONTEXT.md first.

- **Indexable Post** — see CONTEXT.md §Entities. A WordPress content item eligible for embedding generation: `post_type` in `settings.indexable_post_types` (default `['post']`), `post_status` in `{'publish', 'future'}`, no password, not in trash.
- **Recommendable Post** — see CONTEXT.md §Entities. Indexable Post with `post_status = 'publish'`. Strict subset of Indexable. May appear in front-end related-posts lists. On multilingual sites (Polylang/WPML detected), additionally restricted to the same language as the source post.
- **Indexable Text** — see CONTEXT.md §Entities and ADR-0001. The canonical text used to compute a post's embedding: `post_title` (repeated 3×) + manual `post_excerpt` + HTML-stripped `post_content`, truncated to ~6500 words. Changes to Indexable Text invalidate the embedding; changes to other fields do not.
- **Embedding** — A fixed-length numeric vector representing the Indexable Text of a Recommendable Post. Generated by the configured Embedding provider. Stored as base64-encoded `float32` in `postmeta` under key `_sp_embedding`. See ADR-0003.
- **Similarity Graph** — see CONTEXT.md §Structures and ADR-0004. The corpus modeled as a directed weighted graph: nodes are Indexable Posts; outbound edges (top-K) are stored as `_sp_related`; inbound edges are stored as `_sp_inbound`. Maintained incrementally by the crawler; never globally recomputed in normal operation.
- **Recommendation List** — see CONTEXT.md §Structures. The ordered list of K items rendered for a single Recommendable Post. Default size = exactly K (configurable 3–10, default 5). Opt-in quality-bounded mode allows shorter lists with a `score_threshold`.
- **Ranking Mode** — see CONTEXT.md §Structures and ADR-0006. The strategy used to order candidates: `most_relevant` (default, pure cosine), `fresh_first` (recency-weighted), `diverse_mix` (MMR). Featured #1 is always highest cosine in all modes.
- **Recommendation Source** — see CONTEXT.md §Properties. The provenance of a rendered widget: `semantic` (from Similarity Graph), `category-fallback` (silent fallback when semantic unavailable), `none`. Exposed via `data-sp-source` attributes for admin diagnostics; not visible to readers.
- **Derived Data** — see CONTEXT.md §Properties. All `_sp_*` postmeta is deterministically regenerable from posts and settings. Documented as not backup-worthy; restore-from-backup-without-this-data triggers automatic background re-indexing.
- **Crawler** — see ADR-0004. The incremental indexing component. On embedding change, builds a candidate set (existing neighbors + neighbors-of-neighbors + inbound + random sample), scores it, updates outbound + inbound state, propagates changes to affected neighbors. O(K²) per save, independent of corpus size.
- **Cold start** — see ADR-0004 and ADR-0008. The one-time bulk indexing pass that bootstraps the Similarity Graph for existing content. Phased per ADR-0008: brute-force pairwise for the first 200 posts (Phase 1 bootstrap), then graph-traversal kNN against the partial graph for the rest (Phase 2). Batched (50 posts per WP-Cron tick), resumable. Per-post wall-clock is gated by Embedding-provider rate limits (NFR-IDX-1), not by PHP compute.
- **Embedding provider** — The external API that generates embeddings. v1 ships with OpenAI (`text-embedding-3-small`). Internal abstraction allows future providers but is not user-visible in v1.
- **Featured card** — The #1 slot in a Recommendation List, rendered larger than items 2–K (Medium-style "next article" treatment). Always the highest-cosine candidate regardless of Ranking Mode. See ADR-0007.
- **Display surface** — A location on the rendered front-end where related-posts may appear. v1 surfaces: end-of-article via `the_content` filter (auto), `[semantic_posts]` shortcode (manual).
- **Observability panel** — A single-screen admin view showing 24-hour activity counters (embedding calls, error count, memory peak, render-path query count, indexing health). Audited by site owners (UJ-4) and managed-host engineers (UJ-5).
- **Reference environment** — The hardware/software baseline against which performance NFRs are measured. Defined in §9.5: 1 vCPU, 1GB RAM, PHP 8.0, MySQL 5.7, `WP_MEMORY_LIMIT=256M`, no opcache tuning, default WP-Cron, no object cache. Approximates a $5/mo managed-shared-hosting plan.

## 4. Features

### 4.1 Indexing

**Description:** When a post is saved, the plugin computes a hash of its Indexable Text and marks the post dirty only if that hash has changed (see FR-1). An hourly WP-Cron tick processes the dirty queue, fetches embeddings from the configured Embedding provider, stores them as `_sp_embedding` postmeta, and hands off to the Crawler for Similarity Graph propagation (see §4.2). First-publish transitions bypass the dirty queue for immediate freshness. Bulk indexing (FR-4b) is the one-time bootstrap pass for existing content; hash-diff-triggered per-post indexing covers steady state. Realizes UJ-1 and UJ-2.

The indexing path is the only place the plugin makes external HTTP calls. It is rate-limited, resumable, and tolerant of API failures.

#### FR-1: Per-post indexing on save (hash-diff triggered)

When an Indexable Post is saved, the plugin computes the current Indexable Text hash and marks the post dirty *only if* the hash differs from the previous embedding's `_sp_text_hash`. A periodic cron job processes dirty posts. First-publish transitions bypass the dirty queue and regenerate immediately. Realizes UJ-2. See ADR-0002.

**Consequences (testable):**
- A `save_post` hook computes the post's Indexable Text (see §3 Glossary and ADR-0001) — `post_title` repeated 3× + manual `post_excerpt` + HTML-stripped `post_content`, truncated to ~6500 words. Indexable Text construction strips HTML via `wp_strip_all_tags` but does NOT invoke `do_shortcode()`; shortcode tokens remain as raw `[name]` in the input to avoid HTTP side effects and rendering overhead during indexing.
- The hook computes `md5(indexable_text(post))` and compares against `_sp_text_hash` postmeta. If equal: no-op. If different: sets `_sp_dirty = 1` and updates `_sp_text_hash` to the new value.
- The text sent to the Embedding provider when a dirty post is processed is this Indexable Text — never the raw `post_content`.
- An hourly WP-Cron tick processes up to N dirty posts: regenerates embedding via the Embedding provider, updates `_sp_embedding`, clears `_sp_dirty`, and triggers crawler propagation (FR-4) for the post.
- A `transition_post_status` event into `publish` for the first time (post had no prior `_sp_embedding`) bypasses the dirty queue and regenerates immediately, so newly published posts have related-posts data available without waiting for the next hourly tick.
- Autosaves and quick-edit actions that don't change Indexable Text (e.g., category-only edits, slug changes) do not produce dirty marks and do not consume API calls.
- A `wp_trash_post`, password-add, or transition away from `publish` removes `_sp_embedding`, `_sp_related`, `_sp_inbound`, `_sp_text_hash`, `_sp_dirty` for the post, and invalidates any other post's `_sp_related` that pointed to it.
- If embedding generation fails, the post enters a uniform retry queue (3 attempts with exponential backoff per FR-3); failures are surfaced in the observability panel.

**Out of Scope:**
- Real-time inline indexing inside the `save_post` request (kept asynchronous to avoid blocking the editor).
- Draft, auto-save, or pending-status post indexing.

#### FR-2: Bulk index

The plugin exposes a one-time bulk-index action in the admin UI that processes every Indexed-eligible post in the corpus, in batches, with a visible progress indicator. Realizes UJ-1.

**Consequences (testable):**
- Bulk-index UI displays a cost estimate (post count × estimated tokens × current price) before the user confirms.
- Processing runs in batches of 50 posts with 1 second between batches. `[ASSUMPTION: 50 is the batch size; brief specifies. Confirm if changed.]`
- The process honors a 1 request/second rate limit to the Embedding provider.
- The process halts gracefully if PHP memory exceeds 80% of `WP_MEMORY_LIMIT` and resumes on the next tick.
- Bulk index is **resumable**: if interrupted (process killed, WP-Cron disabled mid-run, server reboot), the next tick resumes from the last completed batch. Verified by manually killing PHP mid-job.
- Bulk index for **1,000 posts completes in under 2 minutes** on the Reference environment (§9.5).
- Bulk index **never trips the PHP memory limit** at `WP_MEMORY_LIMIT=256M`.

**Notes:** A WP-CLI alternative `wp semantic-posts index` is required for hosts where WP-Cron is disabled. See FR-11.

#### FR-3: API failure handling

Embedding API calls that fail with retryable errors (HTTP 5xx, 429) enter an exponential backoff retry queue. Posts that fail three times are marked as failed in the observability panel and are not retried until the user re-runs them manually.

**Consequences (testable):**
- A simulated HTTP 503 on the first attempt triggers a retry on the next tick.
- After three consecutive failures, the post stops being retried automatically and is visible in a "failed" counter in the observability panel.
- A failed-post manual re-run action is available from the observability panel.
- Plugin writes fewer than 10 lines to `debug.log` per day in normal operation.

### 4.2 Similarity Graph maintenance

**Description:** The corpus is modeled as a directed weighted **Similarity Graph** (see ADR-0004). The plugin maintains the graph incrementally via a **Crawler**: when a post's embedding changes, the crawler constructs a candidate set from local neighborhood (existing outbound + neighbors-of-neighbors + inbound + random sample), scores it, updates `_sp_related` and `_sp_inbound`, and propagates changes to affected neighbors. Cost is O(K²) per save, independent of corpus size. The graph is never globally recomputed in normal operation.

**Cold start** (one-time, on install with existing posts) is phased per ADR-0008: brute-force pairwise for the first 200 posts to seed a well-formed initial graph, then graph-traversal kNN (the same crawler with a random-entry-points strategy) for posts 201+. O(N²) work is bounded to the bootstrap phase only. Cold start is batched, resumable, and runs in background with a progress indicator.

A periodic **verification pass** samples M posts per week and runs brute-force pairwise for them to detect drift in the crawler's approximation; if disagreement exceeds threshold, an admin notice suggests a full reindex.

#### FR-4: Crawler-based incremental update

When a post's embedding is regenerated (per FR-1), the crawler updates the Similarity Graph using a candidate-set walk rather than a global recompute. Realizes the §1 "cheap at index time" claim and the host-compatibility positioning at scale.

**Consequences (testable):**
- For each embedding regeneration of post X, the crawler builds a candidate set from {X's old `_sp_related`} ∪ {`_sp_related` of those neighbors} ∪ {X's old `_sp_inbound`} ∪ {a small random sample of other Indexable Posts}.
- Cosine similarity is computed only between X and each candidate (~50–150 ops typical), not X against the full corpus.
- The top-K candidates by Ranking Mode score (see FR-4a) become X's new `_sp_related`, replacing the prior value atomically.
- For every post P in {X's old `_sp_related`} ∪ {X's new `_sp_related`} ∪ {X's old `_sp_inbound`}, the crawler recomputes cosine(P, X) with the new X embedding and updates P's `_sp_related` and `_sp_inbound` if the change affects P's top-K.
- A crawler update for a single post completes in under 100ms on the Reference environment (§9.5) regardless of corpus size up to 50,000 posts. Validated by ADR-0008 arithmetic: visit budget 300 × 47 µs cosine + batched postmeta read ≈ ~70 ms per insert.
- The #1 slot in `_sp_related` is the **highest cosine** match in all Ranking Modes, never a recency- or diversity-shifted item.
- On multilingual sites (Polylang or WPML detected via `function_exists` checks), candidate sets are filtered to the same language as the source post via `pll_get_post_language` / `icl_object_id`. Filter `semantic_posts_disable_language_filter` overrides.

**Out of Scope:**
- Per-paragraph or per-section embeddings (whole-post embedding only in v1).
- User-personalized related posts (everyone sees the same list for a given post).

#### FR-4a: Ranking Mode selection

The owner selects one of three Ranking Modes in settings (default: `most_relevant`). The mode determines how candidates are ordered for items 2–K; item 1 is always highest cosine. See ADR-0006.

**Consequences (testable):**
- `most_relevant` (default): pure cosine ordering. Top-K by descending `cosine(post, candidate)`.
- `fresh_first`: ordering by `cosine × exp(-age_days / decay)` where `decay` defaults to 180 days (filter `semantic_posts_recency_decay`).
- `diverse_mix`: item 1 = highest cosine; items 2–K via MMR with `λ` defaulting to 0.7 (filter `semantic_posts_mmr_lambda`): maximize `λ × cosine(post, candidate) − (1−λ) × max_cosine(candidate, already_picked)`.
- Mode changes take effect immediately on the next crawler update; no reindex required.
- All three modes share the same candidate-set construction; only the scoring function differs.

#### FR-4b: Cold start (bulk index bootstrap)

The one-time bulk-index action (FR-2) constructs the initial Similarity Graph. The algorithm is phased per ADR-0008: brute-force pairwise for the first 200 posts (Phase 1), then graph-traversal kNN against the partial graph for the rest (Phase 2, reusing the warm crawler's expansion logic with a random-entry-points strategy). Memory peak ~8.6 MB per cron tick regardless of corpus size. See ADR-0004 (warm crawler) and ADR-0008 (phased cold start).

**Consequences (testable):**
- Cold start processes Indexable Posts in batches of 50 per WP-Cron tick.
- For each post in the batch: fetch embedding via FR-1 mechanism, then compute pairwise cosine against every other already-embedded post; assemble top-K into `_sp_related`; update `_sp_inbound` of selected neighbors.
- Cold start is **resumable**: progress stored in a transient. PHP killed mid-batch resumes from the last completed post on the next cron tick. Never restarts from zero.
- During cold start, posts whose `_sp_related` has not yet been computed render with `category-fallback` source (see FR-6 and §3 Recommendation Source).
- Cold-start wall-clock is dominated by Embedding-provider request throughput, not PHP compute (per ADR-0008 the phased algorithm keeps per-post compute under 100 ms). The 5,000-post target scales linearly from NFR-IDX-1 (1,000 posts under 2 minutes on Reference env).

#### FR-5: Manual reindex and verification

The admin UI exposes:
- **"Reindex all"** — wipes all `_sp_embedding`, `_sp_related`, `_sp_inbound`, `_sp_text_hash`, `_sp_dirty` and triggers a fresh cold start. Used after model changes (see ADR-0005) or for drift recovery.
- **"Retry failed posts"** — re-enqueues all posts marked failed by FR-3.
- **"Run verification pass now"** — runs the periodic verification pass on demand.

**Consequences (testable):**
- "Reindex all" requires explicit confirmation with cost estimate ("This will regenerate embeddings for N posts at approximately $X").
- A weekly verification pass picks 20 random Indexable Posts, runs brute-force pairwise for each against the full corpus, and compares results with the graph's `_sp_related`. If mean rank disagreement exceeds threshold, surface an admin notice with a "Reindex all" call to action.
- Progress visible in the observability panel during all three actions.
- All actions are resumable across cron ticks (same characteristics as cold start).

### 4.3 Display

**Description:** The plugin renders the Related-post list to the front-end via two surfaces: automatic injection after post content via `the_content` filter, and a `[semantic_posts]` shortcode. The template is one Featured card (large thumbnail, larger title, one-line excerpt) followed by four smaller items in a grid. Display reads only from `_sp_related` and a single `WP_Query` — no relevance computation at render time. Realizes UJ-3.

#### FR-6: Auto-injection display

When auto-injection is enabled (default), the related-posts section renders after the post body via the `the_content` filter on single-post views.

**Consequences (testable):**
- Section appears on `is_single()` views only, never on archives, the homepage, or non-Recommendable post types.
- Section is appended **after** the post body content, before any below-content widget areas. `[ASSUMPTION: position is "end of the_content output, before theme footers"; confirm with theme-compatibility test pass.]`
- Section is **not** injected on posts that have a manually-placed `[semantic_posts]` shortcode anywhere in the body (de-duplication).
- When the post has no `_sp_related` (cold start in progress, indexing failed, no API key), the section silently renders category-fallback recommendations with `data-sp-source="category-fallback"`. The reader sees a populated list; the admin sees the diagnostic attribute. See FR-8 and CONTEXT.md §Recommendation Source.
- The section renders nothing only when even category-fallback yields zero candidates (very small corpus, no shared category).

#### FR-7: Shortcode display

The `[semantic_posts]` shortcode renders the related-posts section at the shortcode's location in the post body.

**Consequences (testable):**
- Shortcode honors the same display template as auto-injection.
- Shortcode accepts an optional `count` attribute (3–10) that overrides the site default for that instance.
- Multiple shortcodes in one post render once; subsequent invocations are no-ops.

#### FR-8: Featured-first template (rendering contract)

The display template renders exactly **K items by default** (K = 5, configurable 3–10 in settings). The #1 item is a Featured card; items 2–K render as a smaller grid below. The HTML structure, CSS classes, theme override path, image handling, and customization filters are defined in **ADR-0007 — Rendering contract** (treated as a public contract; changes are additive-only after launch).

**Consequences (testable):**
- Default item count is 5; range 3–10 enforced by settings validation.
- The HTML structure matches ADR-0007 exactly: `<section class="semantic-posts" data-sp-source="...">` wrapping `<article class="semantic-posts-featured">` and `<ul class="semantic-posts-grid">`.
- Featured slot always corresponds to the highest-cosine candidate, regardless of Ranking Mode (see FR-4a).
- Image sizes: the featured card uses `get_the_post_thumbnail($id, 'large')`; grid items use `'medium'`. Sizes overridable via filter `semantic_posts_thumbnail_size` (returns array keyed by `featured` / `grid`).
- Posts without a featured image render with title only (no placeholder image inserted).
- Theme override at `{theme}/semantic-posts/related-posts.php` overrides the plugin's `templates/related-posts.php` via `locate_template`. Custom template locations supported via filter `semantic_posts_template_path` (returns an array of absolute paths checked in order before falling back to the plugin's default).
- Output HTML is **identical for identical post IDs**, regardless of viewer or time — required for full-page cache compatibility (see NFR-PERF-5).
- Default styling does not import external fonts, request external CSS, or load JavaScript.
- The `data-sp-source` attribute on the `<section>` and `data-sp-item-source` on each item reflect the active Recommendation Source (see §3 and FR-6 below).
- Customization filters: `semantic_posts_heading_text`, `semantic_posts_excerpt_length`, `semantic_posts_item_classes`, `semantic_posts_render_html`. Actions: `semantic_posts_before_render`, `semantic_posts_after_render`. All exposed in v1; treated as a stable contract.
- **Quality-bounded mode** (opt-in via settings): when enabled, the list renders between `min_items` (default 3) and `max_items` (default = K), dropping items with cosine score below `score_threshold` (default 0.3). Default OFF — list size stays predictable. Filter `semantic_posts_min_score` overrides the threshold.

**Consequences regarding Recommendation Source:**
- When all top-K slots are filled from the Similarity Graph, the section renders with `data-sp-source="semantic"`.
- When the post has no `_sp_related` yet (cold start in progress, indexing failed, no API key configured), the section silently renders top-K posts from the same category as `category-fallback`. `data-sp-source="category-fallback"` is set so admins can inspect the active source without exposing technical messaging to readers. See §3 Recommendation Source and ADR-0007.
- When even category-fallback yields zero candidates (very small corpus, no shared category), the section renders nothing (`<section>` not output at all). This is the only path that produces an empty surface.

### 4.4 Settings and Configuration

**Description:** A single settings page exposes the owner-configurable surface: API key, embedding model, post types to index, related-post count, Ranking Mode, optional quality-bounded list, display mode, cron frequency, and a live cost preview. All settings are stored in one autoloaded WordPress option under 1KB total (per NFR-HOST-5). The current embedding model lives in this option (per ADR-0005); no per-post model metadata exists.

#### FR-9: Settings UI

Admin → Settings → SemanticPosts exposes a single page with the following fields:

- **OpenAI API key** — masked input, validated by a test API call on save.
- **Embedding model** — dropdown, default `openai/text-embedding-3-small`. Changing the value after data exists triggers a confirmation dialog and full reindex per ADR-0005.
- **Post types to index** — multi-select checkbox list of all public post types registered on the site, default ticked: `post` only. Commerce types (`product`, `download`, `listing`, `event`) display an inline advisory note: *"SemanticPosts is optimized for narrative content. Product and listing recommendations are typically better served by dedicated commerce tools."*
- **Number of related posts** — integer 3–10, default 5.
- **Ranking mode** — radio: `Most relevant` (default) / `Fresh-first` / `Diverse mix`. See FR-4a.
- **Quality-bounded list** — checkbox (default off), labeled *"Show fewer items when matches are weak."* When enabled, exposes `min_items` (default 3) and `score_threshold` (default cosine 0.3). See FR-8.
- **Display mode** — radio: `Auto-inject after content` (default) / `Shortcode only` / `Off`.
- **Cron frequency for dirty processing** — select: `Hourly` (default) / `Every 6 hours` / `Daily`. Governs how often the FR-1 dirty queue is processed.
- **Cost preview** — read-only line showing estimated cost of a full re-index given the current corpus size and current model pricing. Updates live as post-type checkboxes and model selection change.

**Consequences (testable):**
- API key is stored encrypted via WordPress's standard option mechanism. `[ASSUMPTION: encryption uses AUTH_SALT or equivalent constant-based scheme — confirm in architecture.]`
- API key validation makes a single test call to the Embedding provider; failure surfaces a clear error message inline.
- Settings are stored in **one autoloaded option** of fewer than 1KB total. The current embedding model is part of this option (per ADR-0005); no per-post model metadata.
- Settings save action does not trigger any embedding API calls except for the key-validation test call and (if model was changed) the confirmed reindex.
- Changing **embedding model** to a different value triggers a modal: *"This will regenerate embeddings for all N indexed posts, costing approximately $X and taking approximately Y minutes. Continue?"* On confirmation, all `_sp_embedding`, `_sp_related`, `_sp_inbound`, `_sp_text_hash`, `_sp_dirty` postmeta are deleted and the cold-start path (FR-4b) is triggered.
- Changing **post types to index** to add a new type triggers indexing of newly eligible posts on the next cron tick (no immediate API spike). Removing a type triggers cleanup of `_sp_*` data for posts of that type on the next cron tick.
- Changing **Ranking Mode** takes effect on the next crawler update; no reindex required.
- **Corpus-floor notice** — if the detected count of Indexable Posts is fewer than 50 (see CONTEXT.md §Corpus floor), the wizard and settings page surface a one-time non-blocking notice: *"SemanticPosts works best with 50+ posts. You currently have N; recommendations may be sparse until your library grows."* Dismissible. Does not block any indexing operation.

### 4.5 Observability

**Description:** A single admin-page section displays last-24h plugin activity in counters and a small log tail. This serves both the site owner's confidence (UJ-4) and the managed-host audit story (UJ-5). Realizes the brief's host-compatibility positioning by making the plugin's behavior self-evident.

#### FR-10: Observability panel

The admin Settings → SemanticPosts page surfaces a panel showing:
- **Embedding API calls (last 24h)** — count and total cost estimate.
- **Posts in queue / failed** — counts. Failed posts surface alongside a "Retry failed posts" button per FR-3.
- **Last cron tick** — timestamp, posts processed, outcome.
- **Last verification pass** — timestamp, sample size, mean rank disagreement (FR-5).
- **Render-path queries added (last 24h)** — should always be ≤2 per pageview; surfaced as a sanity check.
- **Peak memory in indexing operations (last 24h)** — MB.
- **Recent activity log** — most recent ~50 admin-action and indexing events with timestamps; tail-style, no pagination. Lets the admin verify recent runs and lets host engineers confirm the activity profile during audit (UJ-5).
- **Run indexing now** button — manual trigger that works without WP-Cron.
- **Graceful-restore notice** — when ≥5% of Recommendable Posts lack `_sp_embedding` (typical after restore-from-backup that omitted plugin data per ADR-0003), panel displays a banner: *"Reindexing in progress. X / Y posts indexed."* Auto-dismisses when the ratio drops below 5%.

**Consequences (testable):**
- All counters reflect the last 24 hours from server local time.
- Panel renders in fewer than 200ms even with 5k indexed posts.
- "Run indexing now" button drives the same code path as the scheduled cron tick.
- The graceful-restore notice appears within one cron tick of detecting the missing-embeddings condition and disappears within one cron tick of recovery.

### 4.6 WP-CLI alternative

#### FR-11: CLI commands

The plugin registers WP-CLI commands so that operators on hosts with WP-Cron disabled can run indexing manually.

**Consequences (testable):**
- `wp semantic-posts index` — triggers cold start (FR-4b) with the same batching and rate-limit behavior as the admin UI.
- `wp semantic-posts reindex` — wipes all `_sp_*` postmeta and re-runs cold start. Used after model changes (FR-9) or for drift recovery (FR-5).
- `wp semantic-posts process-dirty` — manually runs the hourly dirty-queue tick on demand (FR-1).
- `wp semantic-posts verify` — manually runs the verification pass (FR-5).
- `wp semantic-posts retry-failed` — clears failed flags from FR-3 and re-enqueues those posts.
- `wp semantic-posts status` — prints the same counters as the observability panel (FR-10).
- All commands return non-zero exit codes on failure; output is parseable (machine-readable `--format=json` supported on `status`).

### 4.7 Clean uninstall

#### FR-12: Uninstall removes all plugin data

On plugin uninstall (not deactivation), the plugin removes all `_sp_*` postmeta, the settings option, and any custom tables. Deactivation preserves data so users can re-enable without losing their index.

**Consequences (testable):**
- Uninstall removes every postmeta entry whose key matches `_sp_*`.
- Uninstall removes the settings option.
- Deactivation leaves data in place; reactivation surfaces a "Resume from existing index" state in the observability panel.

## 5. Non-Goals (Explicit)

- **No external vector database integration in v1.** No Qdrant, Pinecone, Supabase, Typesense, or pgvector. The plugin is self-contained or it loses its positioning.
- **No self-hosted / local embedding models in v1.** API-only. Self-hosted is desired by a vocal minority but adds large surface area for a 1-week build and is not the positioning wedge.
- **No multi-provider abstraction surfaced to users in v1.** Internal abstraction may exist so the architecture allows future providers, but the settings UI ships with one provider only.
- **No Gutenberg block, widget, or alternate templates in v1.** Display surface is `the_content` + shortcode only.
- **No Pro tier, license validation, or payment flow in v1.** Free-only at launch. Pro ships only if Day-90 milestones hit (see §12).
- **No commerce-tuned product recommendations in v1.** Users may opt the `product` post type into indexing, but the plugin does not consume commerce signals (price, stock, attributes, cart context). Marketing copy explicitly does not target commerce buyers.
- **No multilingual marketed features in v1.** Defensive same-language filtering for Polylang/WPML is in scope (FR-4); cross-language recommendations, auto-translation, and multilingual Pro features are not.
- **No A/B testing, click tracking, or engagement analytics dashboard in v1.** The success signal in v1 is plugin reviews and install count, not in-product engagement. Ranking modes are owner-selected by intuition, not by tracked CTR.
- **No mid-content / in-feed contextual injection in v1.** Mida case study suggests +25% CTR potential, but it requires HTML parsing of post content. Deferred to v1.1. `[NOTE FOR PM]` revisit if Day-30 retention is weak.
- **No pop-ups, exit-intent modals, or pre-content "you might like" surfaces in v1, ever.** Nielsen Norman Group classifies these as "needy patterns" that degrade UX; Google penalizes mobile interstitials in search ranking. This is not a v1 deferral — it is a permanent design prohibition.
- **No per-post manual overrides ("pin this related post") in v1.** Owners cannot manually curate the list; the semantic engine is authoritative. Manual override is a Pro candidate.
- **No personalization in v1.** Every reader sees the same list for a given post; per-reader history is not consumed.
- **The plugin is not becoming a search engine, a RAG infrastructure layer, or a developer-facing vector database.** That space is occupied by WPVDB and AI Engine; competing there would dilute the consumer related-posts positioning.

## 6. MVP Scope

### 6.1 In Scope

- One embedding provider integration (OpenAI `text-embedding-3-small`) with internal abstraction for future providers.
- Per-post indexing on `save_post` with **hash-diff trigger** (no API spend on autosaves or non-content edits); first-publish bypass for immediate freshness. See ADR-0002.
- **Cold-start bulk-index** with progress bar and cost preview; batched, resumable across cron ticks. Phased algorithm (brute-force bootstrap + graph-traversal kNN) keeps memory bounded regardless of corpus size. See FR-4b, ADR-0004, ADR-0008.
- **Crawler-based incremental Similarity Graph maintenance** — O(K²) per save, independent of corpus size. See ADR-0004.
- Three user-selectable **Ranking Modes** (default Most relevant; alternatives: Fresh-first, Diverse mix). See ADR-0006.
- Configurable Recommendation List size 3–10 (default 5) with opt-in quality-bounded mode. Configurable multi-select of public post types (default `post`).
- Defensive multilingual filter for Polylang/WPML sites — recommendations restricted to source post's language (~15 LOC). See FR-4.
- Auto-injection display after `the_content` + `[semantic_posts]` shortcode.
- Featured-first 5-item template per the **Rendering Contract** (ADR-0007): public CSS classes, theme override path, customization filters/actions, `data-sp-source` diagnostic attributes.
- Silent **category fallback** when semantic source is unavailable (no API key, cold start in progress, indexing failed). See CONTEXT.md §Recommendation Source.
- Postmeta storage with `_sp_` prefix, treated as Derived Data (no backup needed). See ADR-0003.
- No per-embedding model versioning: model change triggers confirmed wipe-and-reindex via the existing cold-start path. See ADR-0005.
- Periodic **verification pass** (weekly sample of M posts) to detect crawler drift; admin notice on drift exceeding threshold. See FR-5.
- Settings UI: API key, embedding model dropdown, post types, count, ranking mode, quality-bounded toggle, display mode, cron frequency, cost preview.
- Observability panel showing 24h activity, run-now button, retry status, indexing health.
- WP-CLI commands for index, reindex, refresh, retry-failed, status.
- Uniform error handling with exponential backoff (3 retries); failed-post counter and "Retry failed posts" button in observability panel.
- Clean uninstall (removes all `_sp_*` postmeta and the settings option).
- WordPress.org plugin directory submission with i18n-ready strings and `.pot` file.
- Marketing site with reproducible performance benchmark, architecture paragraph + diagram, host compatibility list, the named SEO listicle angle (addendum §J.1), and the two alternatives-to articles (addendum §J.5).
- Pre-launch outreach to ≥1 managed host (WP Engine target; stretch: 2 of {WP Engine, Kinsta, Pressable, Pantheon, GoDaddy Managed, SiteGround}) — see SM-3 and addendum §E.

### 6.2 Out of Scope for MVP

- Self-hosted embedding models (deferred to v1.1 if user demand surfaces).
- Multiple embedding providers in user-facing settings (internal abstraction only).
- Gutenberg block, widget, pre-built display variants beyond the default template (v1.1).
- Mid-content scroll-triggered injection (v1.1 — `[NOTE FOR PM]`).
- Pop-ups, exit-intent modals, pre-content surfaces — never (NN/G "needy patterns" prohibition, see §5).
- Multilingual marketed features (auto-translation, cross-language recommendations, multilingual content management) — defensive same-language filter is in v1, but marketed multilingual support is not.
- Commerce-tuned product recommendations (price/stock/attribute signals) — owners may opt-in `product` post type but plugin treats it as text-only.
- Pro tier, license validation, payment flow (ships only on Day-90 success — §12).
- A/B testing, click tracking, engagement-tracking analytics dashboard.
- Per-post manual overrides ("pin this related post").
- Personalization (everyone sees the same list per post).
- Per-paragraph embeddings.
- Per-embedding model versioning / mixed-model states (see ADR-0005 — wipe and reindex on model change).

## 7. Success Metrics

**Primary**
- **SM-1: Day-90 active installs ≥ 500.** Validates FR-2, FR-6, the overall install-to-value path (UJ-1). **Hard kill criterion if missed.**
- **SM-2: Day-90 reviews ≥ 20, average rating ≥ 4.5 stars.** Validates the quality of the suggestion engine (FR-4) and the install experience (FR-9). **Hard kill criterion if missed.**
- **SM-3: At least 1 major managed host pre-approves the plugin before public launch — soft gate with 4-week timeout.** Outreach to WP Engine (primary target), Kinsta, Pressable, Pantheon, GoDaddy Managed, SiteGround begins per addendum §E. If at least 1 host pre-approves: launch with the host-compat-list claim live. **If no host responds within 4 weeks of the initial outreach email: launch anyway, omit the host-compat-list claim from the WP.org listing, and treat host pre-approval as a Day-30 post-launch milestone.** Outreach continues throughout. Rationale: the disposable-MVP discipline does not tolerate indefinite delay on external-actor responsiveness; launching ungated still preserves the architecture story, which is the substantive claim.

**Secondary**
- **SM-4: Listed in at least one third-party comparison article ("best related posts plugins for WordPress") by Day 90.** Validates the WP Engine ban-list positioning angle and the marketing site's competitive narrative. Outreach to listicle authors (WPBeginner, Kinsta blog, Themeisle, WP Marmite) begins **only after the plugin has 20+ five-star reviews** — addendum §J.2 explains the discipline.
- **SM-5: TTFB delta vs. no-plugin baseline < 5ms on a clean 5,000-post install.** Validates FR-6 and NFR-PERF-1. Benchmark published on marketing site (NFR-MKT-1).
- **SM-6: First-run setup time (install → API key → bulk-index running) under 5 minutes on a fresh install.** Validates FR-9 and UJ-1.
- **SM-7: Day-30 checkpoint — 100+ active installs, 5+ five-star reviews.** Not a kill criterion; a distribution-health diagnostic. **If missed:** pause listicle outreach (SM-4 / addendum §J.2) and diagnose the WP.org install funnel, `readme.txt` conversion copy, and SEO content placement (J.1, J.5) before continuing. Surfaced as a discipline checkpoint so the project doesn't run blind until Day 90's kill gate.

**Counter-metrics (do not optimize)**
- **SM-C1: Reviews mentioning "too many features" or "complex settings."** If this counter rises, scope discipline failed and the plugin is becoming the bloated thing the positioning rejects. Counterbalances pressure to add SM-1-chasing features.
- **SM-C2: Cross-category suggestion rate.** Should be **at least 20% of top-5 items per post cross category boundaries, and no more than 50%.** Below 20% means the plugin is acting like slow category-matching; above 50% means the embeddings are returning noise. Counterbalances raw "relevance" metrics that would reward returning the most-recent intra-category posts.

## 8. Open Questions

1. ~~**Empty-state UX (FR-6).**~~ **Resolved 2026-05-20 (grilling):** silent category fallback per CONTEXT.md §Recommendation Source. The reader never sees an empty widget; the admin sees full transparency via `data-sp-source` attributes and the observability panel. See FR-8.
2. **Plugin slug availability.** Is `semantic-posts` available on WordPress.org? If not, what's the fallback name and does it affect the brand?
3. **API key storage encryption (FR-9).** What's the right WordPress-idiomatic encryption-at-rest pattern for the API key? Architecture-phase decision.
4. **Bulk-index batch size (FR-4b).** Brief specifies 50 posts/batch with 1s pause. Confirm or adjust based on rate-limit headroom for `text-embedding-3-small`.
5. ~~**Crawler per-update performance target (FR-4).**~~ **Resolved 2026-05-20 (grilling pass, ADR-0008):** Per-insert compute validated at ~70 ms on the Reference environment (visit budget 300 × 47 µs cosine + batched postmeta read), comfortably under the 100 ms target. Phase 2 graph-traversal kNN replaces the prior naïve-vs-candidate-set framing.
6. **Featured-card excerpt length.** ADR-0007 says default 160 characters via `semantic_posts_excerpt_length` filter. Confirm cap and ellipsis behavior across themes.
7. **Theme compatibility test bench.** Which themes form the v1 compatibility matrix? Probable: Twenty Twenty-Four, Astra, Kadence, GeneratePress, Hello Elementor; block-based vs. classic. Architecture/test-plan decision.
8. **Observability panel — render performance with 50k posts.** Brief targets up to 10k. Behavior at the upper bound and beyond is a characterization question, not a v1 commitment.
9. **Host-pre-approval contact path.** Who at WP Engine receives the architecture-doc-and-benchmark outreach? Marketing/outreach decision. Affects SM-3 timing — the 4-week timeout starts when the email is sent, so finding the right contact is the rate-limiting step.
10. **Reader UX pattern validity.** The featured #1 + 4-grid end-of-article display in FR-8 is grounded in cited research (Collins 2018, Beierle 2019, NN/G, Mida) and the parallel research subagent (2026-05-20) confirmed end-of-article placement is well-supported. If first 30 days of reviews indicate dissatisfaction, revisit FR-8 template. v1.1 candidates: in-feed mid-content injection (Mida +25% CTR), alternate templates, per-theme defaults.
11. **Verification pass threshold (FR-5).** What constitutes "drift" — what disagreement metric and what threshold triggers the "Reindex recommended" admin notice? Architecture-phase decision.
12. **Random-sample size in crawler candidate sets (FR-4).** How many random Indexable Posts to add to the candidate set for exploration? Trade-off between speed and approximation quality. Architecture-phase decision; default working assumption: ~10 random samples per update.

## 9. Cross-Cutting Non-Functional Requirements

These apply across all features. They are the launch gates from the brief; if any fails, the product premise collapses.

### 9.1 Performance (the core promise)

- **NFR-PERF-1: TTFB delta < 5ms** vs. the same post without the plugin, on a clean 5,000-post install. Benchmark script published on the marketing site, reproducible by anyone.
- **NFR-PERF-2: Exactly 2 added queries per pageview** — one `get_post_meta` for `_sp_related`, one `WP_Query` for the related post IDs. No more.
- **NFR-PERF-3: Zero HTTP requests during request rendering.** Embedding provider is only called from WP-Cron and CLI paths, never from a front-end render handler.
- **NFR-PERF-4: Added memory footprint per request < 1MB.** Embeddings never loaded at render.
- **NFR-PERF-5: Page-cache compatible.** Identical HTML for identical post; verified by inspection against WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Engine cache, Kinsta cache, Cloudflare APO.

### 9.2 Host compatibility (the positioning)

- **NFR-HOST-1: Host pre-approval — soft gate with 4-week timeout.** Same launch gate as SM-3. Outreach initiated per addendum §E.1; if at least 1 host pre-approves, launch with the host-compat-list claim. If no host responds within 4 weeks of the initial outreach email, launch proceeds without that claim and outreach continues post-launch.
- **NFR-HOST-2: Plugin is not on any host disallow list at launch day.**
- **NFR-HOST-3: Object-cache compatible** with Redis and Memcached object cache without manual configuration.
- **NFR-HOST-4: Backup-safe.** Embeddings binary-encoded; filter `semantic_posts_exclude_from_backup` exposed for backup tools; total postmeta added at 5k posts under 50MB.
- **NFR-HOST-5: Autoloaded options ≤ 1KB.** One option total.

### 9.3 Indexing reliability (the silent path)

- **NFR-IDX-1: Bulk index 1,000 posts completes in under 2 minutes** on the Reference environment (§9.5).
- **NFR-IDX-2: Bulk index never trips PHP memory limit** at `WP_MEMORY_LIMIT=256M`. Halts at 80% threshold and resumes.
- **NFR-IDX-3: Indexing is resumable.** If interrupted mid-batch, the next cron tick or CLI invocation picks up at the last completed batch. Verified by manually killing PHP.
- **NFR-IDX-4: WP-Cron-disabled fallback.** All indexing operations work via WP-CLI and via the "Run indexing now" admin button.
- **NFR-IDX-5: API failure tolerance.** Exponential backoff on 5xx/429; three-strike retry queue; failures never crash WordPress.
- **NFR-IDX-6: Log discipline.** Fewer than 10 lines per day in `debug.log` under normal operation.

### 9.4 Suggestion quality (the actual product value)

- **NFR-QUAL-1: Baseline beat. Evaluation protocol — single evaluator (product owner), blind comparison.** On a curated test corpus of 100 posts spanning 5 categories: for each source post, the top-5 SemanticPosts suggestions are presented alongside the top-5 from a category-matching baseline (Jetpack Related Posts or Same Category Posts as proxy), with provenance hidden and order randomized. Per pair, evaluator answers: *"Given the source post, is this suggestion a reasonable next-read for a typical site visitor — yes/no?"* **Pass: at least 3 of 5 SemanticPosts suggestions per source post are judged reasonable AND the SemanticPosts "yes" rate exceeds the baseline "yes" rate, averaged across the 100-post corpus.** `[ASSUMPTION: single-evaluator protocol is acceptable for v1; brief did not specify rater count or methodology. v1.1 may fund multi-evaluator inter-rater eval if quality complaints surface.]`
- **NFR-QUAL-2: Cross-category discovery.** At least 20% of top-5 items per post cross category boundaries; no more than 50% (same as SM-C2).
- **NFR-QUAL-3: No obvious failures.** Zero "completely unrelated" suggestions in manual review of 50 posts. *Rubric for "completely unrelated":* the suggested post is in an entirely different topic domain from the source (e.g., a recipe blog post suggesting a tax-software review, a parenting article suggesting a hardware review). Borderline cross-category suggestions (e.g., "kitchen tools" → "kitchen recipes") do NOT count as failures. Catches embedding-quality and content-extraction bugs.
- **NFR-QUAL-4: Position bias respected.** Featured card is the highest-similarity match, not a random or recency-shuffled pick.

### 9.5 Onboarding (the install-to-value path) and Reference Environment

- **NFR-ON-1: First-run setup completes in under 5 minutes** on a fresh install. Same as SM-6.
- **NFR-ON-2: PHP 8.0+ support.** WordPress minimum. No 8.2+-only syntax.
- **NFR-ON-3: MySQL 5.7+ / MariaDB 10.3+ support.** No MySQL 8 vector-type dependency.
- **NFR-ON-4: Honest cost preview.** Setup wizard surfaces a real dollar estimate (post count × token estimate × current price) before the user starts indexing. Current pricing reference: `text-embedding-3-small` at $0.02 per 1M tokens (May 2026). Embedding 5,000 posts ≈ $0.10.

**Reference environment** (used by NFR-IDX-1, NFR-IDX-2, FR-2, FR-4, and all performance NFRs that name a target hardware tier):
- 1 vCPU, 1GB RAM.
- PHP 8.0, MySQL 5.7.
- `WP_MEMORY_LIMIT=256M`.
- No opcache tuning, default WP-Cron, no object cache, default WP installation with one active theme.
- Approximates a $5/mo managed-shared-hosting plan (Bluehost basic, DreamHost shared starter, Hostinger basic, GoDaddy economy, or equivalent).

If a measurement exceeds the target on the Reference environment, the NFR fails. Better hardware closing the gap is not acceptable evidence — the positioning is *"works on the same hardware your current bloggers are running."*

### 9.6 WordPress.org plugin review (the distribution gate)

- **NFR-WPORG-1: Passes WP.org plugin review on first submission.** Standard hygiene: input sanitization on every settings field, `wp_nonce` on every form, output escaping in every template, no `eval` / `base64_decode` for code execution, no GPL-incompatible bundled libraries.
- **NFR-WPORG-2: Zero PHP notices/warnings/errors** with `WP_DEBUG=true` on a standard install.
- **NFR-WPORG-3: Zero JS console errors** in admin pages.
- **NFR-WPORG-4: i18n-ready.** All user-facing strings wrapped in translation functions; `.pot` file shipped.
- **NFR-WPORG-5: `readme.txt` meets WP.org format.** Tested-up-to current WP version, stable tag matches, screenshots, FAQ.
- **NFR-WPORG-6: WP.org listing compatibility line.** Once any host pre-approves (per SM-3 / NFR-HOST-1), the plugin's `readme.txt` Short Description and the WP.org Description include the line: *"Tested with WP Engine, Kinsta, SiteGround, Cloudways, Pressable."* Listing copy updated as additional hosts approve. Distinct from NFR-MKT-3, which covers the marketing site fold; both surfaces are required.

### 9.7 Marketing site (the conversion gate)

- **NFR-MKT-1: Reproducible performance benchmark** publicly available with code anyone can run on their own install.
- **NFR-MKT-2: One-paragraph architecture document + simple diagram** visible from the marketing site homepage.
- **NFR-MKT-3: Compatibility list visible above the fold** once any host pre-approves ("Tested with WP Engine, Kinsta, SiteGround, Cloudways…"). If SM-3's 4-week timeout fires without a host response, this requirement defers to Day-30 post-launch.
- **NFR-MKT-4: Named SEO listicle content shipped at launch** — addendum §J.1. Article targets the high-intent search "plugins banned by WP Engine 2026," names the current disallow-list entries (Contextual Related Posts, Similar Posts, Dynamic Related Posts, SEO Auto Links & Related Posts), and positions SemanticPosts as the host-safe alternative.

## 10. Constraints and Guardrails

### 10.1 Cost transparency

The buyer must always know what the next action will cost in API spend before they take it. Setup wizard surfaces re-index cost in real dollars; bulk-index trigger surfaces the same; settings page surfaces it as a live estimate. The plugin never spends the buyer's API budget without an explicit confirmation step.

### 10.2 Data residency

Post content is transmitted to the configured Embedding provider for embedding generation. This is disclosed plainly in the setup wizard and in `readme.txt`. v1 does not offer a self-hosted alternative; users for whom this is a blocker are explicitly out of scope (see §2.3).

### 10.3 Host-policy alignment

The plugin's architecture is shaped by the brief's host-compatibility table: zero render-path HTTP, zero render-path expensive queries, bounded memory, capped log volume, opt-out for backup tools, autoloaded-option discipline, clean uninstall. These are not nice-to-have NFRs — they are the positioning.

If any architectural decision conflicts with a host-compatibility constraint, the host-compat constraint wins. Concrete examples of what loses: an indexing-performance optimization that requires loading the full corpus's embeddings into memory at once (would violate NFR-IDX-2); a display optimization that adds a 3rd request-path query (would violate NFR-PERF-2); a settings-UX improvement that stores per-post overrides in autoloaded options (would violate NFR-HOST-5). In each case, the host-compat constraint wins and the optimization is dropped or redesigned.

## 11. Why Now

Three timing pressures make 2026 the right window:

1. **Embedding API pricing has collapsed.** `text-embedding-3-small` is $0.02/1M tokens (May 2026), making it economically viable for a 1,000-post site to be indexed for under $0.05. Three years ago this same workload would have been a serious dollar cost.
2. **Managed-host disallow lists are still real and still public.** WP Engine's list still excludes Contextual Related Posts, Similar Posts, Dynamic Related Posts, and SEO Auto Links & Related Posts as of May 2026. The pain pattern is current; the positioning is not chasing a closed window.
3. **No big-SEO suite has shipped semantic relatedness.** Yoast, AIOSEO, Rank Math, and Jetpack have not added embedding-based related-posts as of May 2026 (verified by research subagent 2026-05-20). The category is not yet absorbed by an incumbent.

These factors are not durable. Big-SEO suites can ship in any quarterly release; managed-host policies can shift; API pricing is unlikely to rise but the strategic-cost calculus could. The 1-week MVP and 90-day kill criterion are explicitly designed around this volatility — see addendum §F for the disposable-build rationale.

## 12. Monetization

**`[PROVISIONAL — revisited at Day 90.]`** v1 ships free on WordPress.org. No paid tier, no license validation, no payment flow.

A Pro tier ships only if the free tier crosses **1,000 active installs within 90 days** — above the 500-install kill criterion in SM-1. The kill criterion (500) determines whether the project continues at all; the Pro threshold (1,000) determines whether the project monetizes. Outcomes:

- **Below 500 installs at Day 90:** project ends.
- **500–999 installs at Day 90:** project continues free-only; revisit Pro at Day 180 if growth holds.
- **1,000+ installs at Day 90:** Pro work begins; pricing-page PRD opens.

Provisional Pro shape (revisited when Pro work begins; numbers and feature list are anchors, not commitments):
- Unlimited posts indexed (free tier capped at 200 indexed posts).
- Additional embedding providers exposed (Voyage, Cohere).
- Self-hosted embedding model option (if research validates the build cost).
- Premium templates and Gutenberg block.
- Priority support.
- Pricing anchors: $49/year single-site, $129/year multi-site (up to 5).

Pricing details and pricing-page positioning go to a follow-up PRD when Pro work begins. Full provisional structure in addendum §I.

## 13. Platform

WordPress only.

- **WordPress core:** v6.0+ `[ASSUMPTION: v6.0+ covers ~85% of active installs per WP.org stats; verify at submission time.]`
- **PHP:** 8.0+ (WordPress minimum).
- **Database:** MySQL 5.7+ / MariaDB 10.3+.
- **Object cache:** Redis or Memcached compatible (no manual configuration required).
- **Front-end:** Renders inside any theme that respects `the_content` filter for auto-injection, or renders shortcodes for shortcode mode. Block-based and classic themes both supported.

Reference environment for all hardware-dependent NFRs and FRs: §9.5.

Out of platform scope for v1: WordPress multisite (one site per install in v1; multisite testing deferred), headless WordPress (Gutenberg/REST surfaces not v1 priorities).

## 14. Aesthetic and Tone

The plugin has a small visible surface (one admin page, one front-end section) and an even smaller spoken surface (setup wizard copy, settings labels, observability panel text).

**Voice and tone (chat / UI copy):** plain, honest, slightly understated. The kind of copy where a single sentence does the work of three. No exclamation marks. No "Awesome!" or "Let's get started!" framings. Cost previews show real dollars in plain prose. Error messages name the problem and the next step. Examples:

- Setup wizard CTA: *"Index your 1,247 posts for about $0.10."*
- Observability success line: *"Last 24 hours: 12 embedding calls, 0 errors."*
- Admin-only notice during cold start (readers see silent category fallback per FR-6, never an empty section): *"Indexing your library. 847 of 1,247 posts processed."*

**Visual:** the featured card carries the visual weight of the front-end section. Default CSS uses system fonts, no external loads, neutral spacing — designed to inherit from the host theme rather than impose a brand. CSS classes `.semantic-posts` and `.semantic-posts-featured` are documented for theme overrides.

## 15. Assumptions Index

Inline assumptions tagged throughout the document:

1. **§0** — Plugin slug `semantic-posts` is available on WordPress.org. Verify before submission.
2. ~~**UJ-2**~~ — *Resolved 2026-05-20 (grilling): silent category fallback per CONTEXT.md §Recommendation Source. See updated UJ-2 wording and FR-8.*
3. **FR-4b** — Bulk-index (cold start) batch size is 50 posts with 1s pause. Confirm against `text-embedding-3-small` rate-limit headroom in architecture phase.
4. ~~**FR-4** — Tagged `[TARGET — pending architecture-phase validation]`~~ — **Resolved by ADR-0008.** Crawler per-update budget validated at ~70 ms on Reference environment via the phased cold-start design (visit budget 300, batched postmeta reads). Independent of corpus size up to 50k as originally targeted.
5. **FR-6** — Auto-injection position is "end of `the_content` output, before theme footers." Confirm with theme-compat test pass.
6. **FR-9** — API key encryption uses an `AUTH_SALT`-derived scheme. Architecture-phase decision.
7. **§13** — WordPress core v6.0+ covers ~85% of active installs; verify at submission time.
8. **NFR-QUAL-1** — Single-evaluator protocol acceptable for v1. v1.1 may fund multi-evaluator inter-rater eval if quality complaints surface.
9. **FR-4** — Random-sample size in crawler candidate sets: ~10 random Indexable Posts per update. Confirm in architecture phase against approximation quality vs. speed trade-off.
10. **FR-5** — Verification-pass disagreement threshold for triggering "Reindex recommended" admin notice: unspecified. Architecture-phase decision.

Each [ASSUMPTION] above must either be confirmed by the user (in Finalize step 4) or moved to Open Questions for resolution in the architecture phase.
