# Product Brief: SemanticPosts (working name)

> **Mode:** disposable 1-week MVP, kill criterion at 90 days. Optimized for time-to-market over long-term moat.

## One-line pitch

Related posts that are actually related, without slowing your site down.

## Problem

WordPress blog owners pick between two bad related-posts options:

1. **Accurate but slow:** YARPP, Contextual Related Posts. Compute relevance per request via brute-force MySQL queries. On sites with 500+ posts, page loads suffer. Several are still on managed-host disallow lists (WP Engine still bans Contextual Related Posts, Dynamic Related Posts, Similar Posts as of 2026).
2. **Fast but dumb:** Jetpack Related Posts, Same Category Posts. Category/tag matching. Suggestions are visibly poor — the same 3 recent posts from the same category on every page.

The reader leaves after one article instead of going down a rabbit hole. The owner loses pageviews. Nobody has fixed this with the architecture that obviously fits the problem: precompute once, serve from cache.

## Solution

Generate semantic embeddings for each post when it's saved. Store as postmeta. Compute top-N related posts once at index time. Serve from postmeta with a single indexed query — zero relevance computation on the request path.

- Page-load cost: one `get_post_meta` call. Lower than category-matching plugins.
- Suggestion quality: semantic — finds cross-category connections category matching misses entirely.
- Host compatibility: passes every managed-host performance check because nothing expensive runs at request time.

## Target user

WordPress site owners with 200–10,000 posts who care about reader engagement: bloggers, niche site operators, content marketers, course/membership sites. Technical enough to install a plugin and paste an API key. Not developers.

## Positioning

**What it is:** a self-contained semantic-relevance engine. No external vector database. No Qdrant, no Pinecone, no Supabase, no Typesense. The plugin uses your WordPress database (postmeta) and one embedding API of your choice.

**Why this matters:** every existing embedding-based plugin in this space (WPVDB, SemantiQ, AI Vector Search Semantic, OC3 Semantic box, Typesense plugin) requires external infrastructure most bloggers can't and won't set up. SemanticPosts is the only one that works on shared hosting with nothing beyond an OpenAI key.

The headline message to the buyer is the outcome, not the tech: *"Related posts that are actually related, without slowing your site."* "Semantic" and "embeddings" are credibility details in the FAQ, not the pitch.

## v1 scope — 1 week part-time

**Goal:** ship the smallest possible version that proves there's demand. No Pro tier yet, no premium templates, no Gutenberg block, no analytics dashboard.

**In scope:**
- One embedding provider: OpenAI `text-embedding-3-small` (cheap, fast, ubiquitous API key)
- Indexing model: **Similarity Graph maintained incrementally via a crawler**. Cold start uses batched, resumable brute force (~30 min for 5k posts). Ongoing edits via small candidate-set propagation — O(K²) per save, independent of corpus size. See [ADR-0004](../../docs/adr/0004-crawler-based-indexing.md).
- Storage: all plugin data in postmeta with `_sp_` prefix, treated as derived (regeneratable, excluded from backups). No custom tables. See [ADR-0003](../../docs/adr/0003-storage-postmeta-derived.md).
- Single-post indexing triggered on `save_post` via hash-diff (no API spend on autosaves or non-content edits). First-publish transition triggers immediate regeneration. See [ADR-0002](../../docs/adr/0002-embedding-regeneration-triggers.md).
- Indexable Text composition: title (3× weight) + manual excerpt + cleaned content, truncated. See [ADR-0001](../../docs/adr/0001-indexable-text-composition.md).
- Bulk reindex action with progress bar in admin; resumable across cron ticks.
- Auto-injection after post content via `the_content` filter.
- `[semantic_posts]` shortcode as fallback.
- Multi-language defensive filter: detects Polylang/WPML, restricts candidates to same language. ~15 LOC, prevents broken UX on multilingual sites without claiming full multilingual support.
- **Three configurable ranking modes** (default "Most relevant"): pure cosine / fresh-first (recency boost) / diverse mix (MMR). Featured #1 is always highest cosine across modes. See [ADR-0006](../../docs/adr/0006-recommendation-ranking-modes.md).
- Settings page:
  - API key
  - Number of related posts (default 5, range 3–10)
  - Multi-select of public post types (default: `post`); advisory note for commerce types
  - Ranking mode dropdown
  - Optional quality-bounded list (checkbox to allow shorter lists when matches are weak; default off — sizes stay predictable)
  - Where to display (auto / shortcode only / off)
- Rendering: 5 items, #1 featured (larger thumbnail + title + excerpt, Medium-style hero), items 2–5 as a smaller grid. Title-only fallback when no featured image. Theme override via `{theme}/semantic-posts/related-posts.php`. Diagnostic `data-sp-source` attributes. See [ADR-0007](../../docs/adr/0007-rendering-contract.md).
- No model versioning per embedding — changing model triggers wipe + cold start (reuses existing mechanism). See [ADR-0005](../../docs/adr/0005-no-per-embedding-model-versioning.md).
- Graceful degradation: silent category fallback per-post when semantic source is unavailable (no API key, cold start in progress, single-post indexing failure). Full transparency for admins via dashboard + `data-sp-source` attributes.
- Error handling: uniform retry-with-backoff (3 tries), failures flagged in admin with "Retry failed posts" button. Cost estimate banner before any bulk operation.

**Explicitly out of v1:**
- Self-hosted/local embedding models
- Multiple embedding providers (Voyage, Cohere)
- Gutenberg block / widget / pre-built display variants beyond the default template
- Full multilingual Pro features (auto-translation, cross-language recommendations) — defensive same-language filter is in v1, but multilingual is not a marketed feature
- WooCommerce-tuned product recommendations (semantic works on `product` if opted in, but no commerce-specific signals)
- Pro tier, license validation, payments
- A/B testing, analytics, click tracking, engagement-based mode selection
- Inline content links, sidebar widgets
- In-feed contextual injection between H2s for long-form (deferred to v1.1 per UX research)

## Pricing — deferred

No paid tier at launch. Free on WordPress.org with the user's own OpenAI API key. Pro tier ($49/year, unlimited posts + hosted embeddings + premium support) ships only if free tier hits 1,000 active installs in 90 days. Premature monetization slows distribution.

## Distribution

WordPress.org plugin directory. Discovery driven by:

- In-directory search on "related posts," "related content," "post recommendations," "semantic"
- "Alternatives to YARPP" / "Alternatives to Jetpack Related Posts" SEO content on a one-page marketing site
- Listicle inclusion (WPBeginner, Kinsta, Themeisle) — outreach only once plugin has 20+ five-star reviews
- Targeted angle: "5 related-posts plugins still banned by WP Engine in 2026 — and what to use instead" (Contextual Related Posts, Similar Posts, Dynamic Related Posts, SEO Auto Links & Related Posts are still on the list; YARPP is not — removed 2022)

No personal audience-building required.

## Competitive map (after validation)

| Plugin | Architecture | Status | Threat level |
|---|---|---|---|
| YARPP | Brute-force MySQL, per-request | Optimized 2022, unbanned by WP Engine, large install base | Medium — entrenched but architecturally old |
| Contextual Related Posts | Brute-force MySQL, per-request | Still banned by WP Engine | Low — pain is the wedge |
| Jetpack Related Posts | Cloud-based, category-driven | Bundled with Jetpack | Medium — bundled distribution |
| Same Category Posts | Tag/category matching | Stagnant | Low |
| **WPVDB** | Native MySQL vectors, semantic | GitHub only, 11 stars, search-first | **Highest** — architecturally identical, but developer-tool framing leaves room for a polished consumer plugin |
| AI Vector Search Semantic | Requires Supabase | WP.org, <10 active installs, WooCommerce-focused | Low |
| SemantiQ Search | Requires Qdrant | GitHub only | Low |
| OC3 Semantic box | OpenAI + Pinecone | WP.org | Low |
| Typesense WP Vector | Requires self-hosted Typesense | WP.org | Low |
| AIOSEO / Yoast / Rank Math | Could ship in a quarterly release | Yoast launched Schema Aggregation March 2026 | Medium — they may enter the category |

## Host compatibility (the positioning is real)

The pitch only works if the plugin actually survives managed-host audits. The architecture neutralizes the standard ban triggers; the launch process closes the residual risk.

**Structural guarantees (built into the architecture):**

| Ban trigger | How SemanticPosts handles it |
|---|---|
| Expensive queries per pageview | 2 indexed queries, <1ms. Lighter than `Same Category Posts`. |
| HTTP at render time | Zero. OpenAI is only called from WP-Cron background jobs. |
| Memory bloat per request | Render loads ~1KB (array of post IDs). Embeddings never loaded at render. |
| Autoloaded option bloat | One ~500 byte settings option. Embeddings in postmeta (not autoloaded). |
| WP-Cron runaway | Batches of 50 posts, 1s pause between batches, 1 req/sec rate limit, halts at 80% memory limit, honors `DISABLE_WP_CRON`. |
| Backup inflation | Embeddings stored as base64-encoded `float32` binary (~6KB/post vs ~15KB JSON). Filter to exclude `_sp_embedding` from backup tools; only `_sp_related` (output) is essential. Embeddings are regeneratable. |
| Cache conflict | Render is deterministic and synchronous. Full-page cache (WP Engine cache, Kinsta cache, Cloudflare APO, WP Rocket) works unmodified. |

**Pre-launch certification process:**

1. Publish a reproducible performance benchmark on the marketing site: TTFB delta, queries added per pageview, memory peak. With code anyone can run.
2. Email WP Engine, Kinsta, Pressable, Pantheon, GoDaddy Managed before public launch — offer the plugin for their review. Goal: pre-approval on the "compatible" list. YARPP used this same process to get unbanned in 2022.
3. Public architecture document (one paragraph + diagram) so host support engineers can audit in 30 seconds.
4. Built-in observability in the admin panel: "Last 24h — 12 embedding calls, 0 page-render queries, 8MB peak memory." Hosts can verify without contacting the developer.
5. Clean uninstall: remove postmeta and custom data on deactivation. (Hosts prefer this.)
6. Compatibility list in the plugin's WordPress.org title and copy: "Compatible with WP Engine, Kinsta, SiteGround, Cloudways, Pressable" — both a buyer signal and a host signal.

**Residual risk and recovery plan:**

If a host adds the plugin to a disallow list anyway (unlikely but possible): direct contact with their engineering team, share the architecture doc and benchmarks, request review. YARPP successfully went through this loop. The architecture genuinely doesn't trigger their concerns; the conversation is "show us the evidence" and we have it.

## Acceptance criteria — must all pass before launch

These are the things that, if missing, the entire product premise collapses. They are not nice-to-haves. Every one of them is a launch gate.

### Performance (the core promise)

- **TTFB delta:** rendering a post with related-posts section adds **<5ms** vs. the same post without the plugin. Measured on a clean install with 5,000 posts. Benchmark script published.
- **Queries per pageview:** exactly **2 added queries** (one `get_post_meta` for `_sp_related`, one `WP_Query` for the related post IDs). No more.
- **HTTP requests during render:** **zero.** Never call OpenAI or any external service from a frontend request handler.
- **Memory per request:** added memory footprint **<1MB**. Embeddings are never loaded at render time.
- **Page-cache compatibility:** identical HTML output for identical post. Compatible with WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Engine cache, Kinsta cache, Cloudflare APO (verified by inspection).

### Host compatibility (the positioning)

- **At least 1 major managed host pre-approves** the plugin before public launch. Target: WP Engine (highest visibility). Stretch: 2 of {WP Engine, Kinsta, Pressable, Pantheon, GoDaddy Managed, SiteGround}.
- **Not on any host disallow list** at launch day.
- **Object-cache compatible:** works with Redis/Memcached object cache without manual configuration.
- **Backup-safe:** embeddings binary-encoded; filter `semantic_posts_exclude_from_backup` available; total postmeta added at 5k posts **<50MB**.
- **Autoloaded options:** **1 option, <1KB** total.
- **Clean uninstall:** removes all `_sp_*` postmeta and plugin tables on uninstall.

### Indexing reliability (the silent path)

- **Bulk index 1,000 posts** completes in **<2 minutes** on a $5/mo shared host (1GB RAM, no WP-Cron tuning).
- **Bulk index never trips PHP memory limit** on `WP_MEMORY_LIMIT=256M`. Halts at 80% threshold and resumes.
- **Resumable:** if the process is killed mid-batch, next cron tick picks up where it left off via transient state. Verified by manually killing PHP mid-job.
- **WP-Cron disabled fallback:** plugin offers `wp semantic-posts index` CLI command + admin "Run indexing now" button that work without WP-Cron.
- **API failure handling:** OpenAI 5xx/429 responses trigger exponential backoff. Posts that fail 3 times go to a retry queue, not a poison pill. Failures logged, never crash WordPress.
- **No log spam:** plugin writes <10 lines to `debug.log` per day in normal operation.

### Suggestion quality (the actual product value)

- **Baseline beat:** on a test corpus of 100 posts across 5 categories, the plugin's top-5 related posts must beat category-matching baseline in a blind manual eval. Standard: at least **3 of 5 suggestions per post** judged "more relevant" than category baseline.
- **Cross-category discovery:** at least **20% of top-5 suggestions** cross category boundaries (the semantic differentiator). If everything stays inside the source category, the plugin is just slow category matching.
- **No obvious failures:** zero "completely unrelated" suggestions in manual review of 50 posts. (Catches embedding quality issues, post-content extraction bugs.)
- **Position bias respected:** the #1 featured slot is the highest-score match, not a random pick from top-5.

### Onboarding (the install-to-value path)

- **First-run setup completes in <5 minutes** on a fresh install: install → API key → trigger bulk index → see first related posts appear.
- **Works on PHP 8.0+** (the WordPress minimum). No PHP 8.2+-only syntax.
- **Works on MySQL 5.7+ / MariaDB 10.3+.** No MySQL 8 vector-type dependency.
- **Setup wizard surfaces API cost honestly:** "Indexing your 1,247 existing posts will cost approximately $0.13 via OpenAI." Buyer knows before clicking.

### WordPress.org approval (the distribution gate)

- **Passes WP.org plugin review** on first submission. Common gates: input sanitization on every settings field, `wp_nonce` on every form, output escaping in every template, no `eval`/`base64_decode`/remote code execution, no GPL-incompatible bundled libraries.
- **No PHP notices/warnings/errors** with `WP_DEBUG=true` on a standard install.
- **No JS console errors** in the admin pages.
- **i18n-ready:** all user-facing strings wrapped in translation functions; `.pot` file shipped.
- **Readme.txt** meets WP.org format: tested up to current WP version, stable tag matches, screenshots, FAQ section.

### Marketing site (the conversion gate)

- **Reproducible performance benchmark** publicly available, with code anyone can run on their own install.
- **One-paragraph architecture doc** + simple diagram visible from the homepage.
- **Compatibility list** ("Tested with WP Engine, Kinsta, SiteGround, Cloudways...") visible above the fold once any host pre-approves.

### 90-day kill criterion (the discipline)

- **500+ active installs**
- **20+ reviews, average ≥4.5 stars**
- **Listed in ≥1 third-party comparison article** ("best related posts plugins for WordPress")

If any of these three fail at day 90, abandon the project and apply lessons to the next attempt. No "give it one more month" extensions.

## Risks (acknowledged, not blockers in disposable-build mode)

- **Big SEO suite ships semantic related posts.** AIOSEO, Yoast, or Jetpack adding this in 2026 is plausible. In disposable-build mode, this is acceptable — you'll have captured 6+ months of revenue by then.
- **API cost surprise.** 5,000 posts × 1 embedding ≈ $0.50 one-time on `text-embedding-3-small`. Surfaced honestly in setup wizard.
- **WP.org review hygiene.** Sanitization, escaping, nonces. Standard 1–2 week review.
- **Reader UX pattern may be wrong.** The "list at end of post" format is convention, not proven optimal. *Currently being researched in parallel; brief will be updated when findings land.*

## Success criteria (kill criterion explicit)

- **Day 30 post-launch:** 100+ active installs, 5+ five-star reviews. If miss → diagnose distribution.
- **Day 90 post-launch:** 500+ active installs, 20+ reviews, listed in at least one comparison article. **If miss → kill the project, move on.**
- **Day 180:** If 1,000+ installs and clear demand signal → ship Pro tier, target $5k captured over the following 6 months.

Anything past day 180 is upside, not commitment. If incumbents catch up at month 9 and the project plateaus at $4–5k, that's a win — abandon and start the next one.

## UX rationale (research-backed)

The display choices in v1 are not convention — they're chosen from the strongest available evidence on related-content UI:

- **End-of-article placement** wins. Center for Media Engagement (UT Austin) A/B-tested news sites: end-of-article links generated **55% more clicks than mid-article**, with the gap widening on mobile (+61%) and phablet (+82%). Confirmed by Nielsen Norman Group as the position of maximum reader receptivity.
- **5 items, not 3 or 10.** Beierle et al. 2019 measured CTR across 41M recommendations: 0.41% with 1 item, 0.09% with 15 items. The sweet spot is **5–6 items**; below that under-uses real estate, above that triggers choice overload.
- **Featured first item.** Collins et al. 2018 (10M recommendations) confirmed position bias: the #1 slot receives **53–87% more clicks** than expected. Treating it as a hero card (Medium-style "next article") concentrates value where attention already is.
- **No pop-ups, no exit-intent, no pre-content.** NN/G classifies these as "needy patterns" that degrade user experience; Google penalizes mobile interstitials in search ranking.

### Deferred to v1.1 / Pro

**In-feed contextual injection between H2s for long-form posts (>2000 words), triggered by scroll-depth (~60% read).** Mida case study showed +25% CTR by ensuring readers actually *see* the recommendation; ~60% of readers never reach the end of long articles, so end-of-post placement alone misses them. Implementation requires parsing post content and injecting HTML mid-stream — more complex, out of v1, but the clearest differentiation opportunity in v1.1.

Sources: [Collins et al. 2018](https://arxiv.org/abs/1802.06565), [Beierle et al. 2019](https://link.springer.com/article/10.1007/s00799-019-00270-7), [Center for Media Engagement](https://mediaengagement.org/research/links/), [NN/G — Related Content Boosts Pageviews](https://www.nngroup.com/articles/related-content-pageviews/), [NN/G — Recommendation Guidelines](https://www.nngroup.com/articles/recommendation-guidelines/), [Mida — A/B testing below the fold](https://www.mida.so/blog/ab-test-below-the-fold).

## What's been validated

- ✅ AIOSEO/Yoast/Jetpack have no public roadmap entry for embedding-based related posts
- ✅ Vector storage is not the risk feared in v0 of this brief — pure-PHP cosine over postmeta is fine at 5k posts; `mysql-vector` PHP library and MySQL 8.0.32+ native vectors are fallbacks if needed
- ✅ Performance complaints are the dominant pain in 1–3 star reviews of brute-force plugins
- ⚠️ "First" claim from v0 of this brief was wrong — multiple semantic plugins exist. Positioning shifted to "self-contained, no external infrastructure"
- ⚠️ YARPP host-ban angle from v0 is outdated (unbanned April 2022). Positioning shifted to currently-banned plugins (Contextual Related Posts, Similar Posts, Dynamic Related Posts)
- ⏳ Optimal UX surface for related content recommendation — research in flight
