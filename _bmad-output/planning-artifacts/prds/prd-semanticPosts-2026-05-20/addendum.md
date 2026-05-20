# PRD Addendum — SemanticPosts

Companion to `prd.md`. Holds rejected alternatives, technical-how, sizing data, deep-dive rationale, and research grounding that earned a place but does not fit the PRD's main narrative. Downstream artifacts (architecture, epics/stories, sprint plan) read both documents.

## A. Rejected Alternatives

### A.1 v0 brief framing rejected: "first plugin with semantic embeddings"

The first version of the project brief claimed SemanticPosts as the first semantic-embeddings related-posts WordPress plugin. Verification surfaced multiple existing plugins in the space (WPVDB, SemantiQ, AI Vector Search Semantic, OC3 Semantic box, Typesense WP Vector). The "first" framing was abandoned; positioning shifted to **"self-contained, no external infrastructure required."** This is a narrower and more honest wedge — every other embedding plugin requires Qdrant, Pinecone, Supabase, Typesense, or pgvector. SemanticPosts is the only one that works on a stock managed-host WordPress install with nothing but an OpenAI key.

### A.2 v0 host-ban angle: YARPP — rejected

The original brief leaned on YARPP being host-banned. YARPP was unbanned by WP Engine in April 2022. The current ban targets are Contextual Related Posts, Similar Posts, Dynamic Related Posts, and SEO Auto Links & Related Posts (verified 2026-05-20 against WP Engine's current public disallow list). Positioning copy uses these names, not YARPP.

### A.3 Vector storage: pgvector — rejected for v1

pgvector is the natural fit but requires PostgreSQL, which WordPress does not ship with. Forcing a Postgres dependency would gate adoption on managed-host support and self-hosted Postgres tolerance — out of scope for a 1-week MVP. Native MySQL 8 vector type would also gate on host MySQL version. **Decision:** ship pure-PHP cosine over base64-encoded `float32` postmeta vectors. Validated by brief preflight: works at 5k-post corpora in under 60 seconds of refresh-tick time on commodity hardware.

Fallback options if performance is later proven insufficient at upper-bound corpora:
- `mysql-vector` PHP library (in-MySQL vector ops, no version dependency)
- MySQL 8.0.32+ native `VECTOR` type (gates on host MySQL version)
- pgvector if user demand surfaces for Postgres-backed WordPress installs

### A.4 Self-hosted / local embedding model in v1 — rejected

Self-hosted sentence-transformers would remove the API dependency and the data-residency concern. It is also the largest implementation surface in the plugin: model selection, packaging, inference performance, version updates, model size in plugin download. Cost: turns a 1-week MVP into a 3–4-week build. v1 ships API-only. Self-hosted is a Pro candidate or a v1.1 extension if user demand surfaces.

### A.5 Multi-provider settings UI in v1 — rejected

Voyage, Cohere, and other providers have measurably different embedding spaces. Surfacing provider choice to v1 users would require explaining the cost/quality tradeoff and managing the migration when a user switches providers (existing embeddings become incompatible). Decision: ship internal provider abstraction so additional providers can be added without refactor, but v1 settings expose OpenAI only. Provider migration logic is deferred.

### A.6 Mid-content / in-feed contextual injection — rejected for v1

Mida's case study suggests **+25% CTR** for scroll-triggered mid-content injection (at ~60% read depth, between H2s, in long-form posts >2,000 words). Reaches the ~60% of readers who never reach the end of long articles.

Implementation requires parsing post HTML, identifying suitable injection sites, and injecting markup mid-stream. Not buildable in v1's time budget. Tagged in PRD as `[NOTE FOR PM]` to revisit if Day-30 retention signals weakness.

## B. Options Matrices

### B.1 Embedding provider choice (v1)

| Provider | Model | Price (May 2026) | Dims | Notes |
|---|---|---|---|---|
| **OpenAI** | `text-embedding-3-small` | $0.02 / 1M tokens (batch $0.01) | 1536 | **Chosen for v1.** Lowest friction (most buyers already have an OpenAI key); cheap enough that 5k posts cost ~$0.10. |
| OpenAI | `text-embedding-3-large` | $0.13 / 1M tokens | 3072 | Better quality at 6.5× cost; v1 doesn't need it. Pro candidate. |
| Voyage | `voyage-3` | varies | 1024 | Competitive quality, smaller user base. Provider-abstraction candidate. |
| Cohere | `embed-v4` | varies | up to 1536 | Competitive; not in v1. |
| Self-hosted | `sentence-transformers` (BAAI/bge-small-en-v1.5 et al.) | free | varies | Removes API dependency; large packaging cost. v1.1 / Pro. |

Pricing reference confirmed 2026-05-20 by research subagent.

### B.2 Auto-injection position

| Position | Theme compatibility | Cache compatibility | Reader receptivity (research) |
|---|---|---|---|
| **End of `the_content`, before theme footers** | High — works on any theme respecting the filter | Full | **Highest** — CME +55% CTR vs. mid-article |
| Bottom of page (after sidebars / footer widgets) | Lower — depends on theme structure | Full | Low — below the fold of most layouts |
| After first paragraph | Requires HTML parsing | Full | Mid-tier; "needy" per NN/G |
| Mid-content (scroll-triggered) | Requires HTML parsing + JS | Full | Highest in long-form (Mida) |

Chosen: end of `the_content`. Mid-content is the v1.1 differentiation lever.

### B.3 Featured-first vs. uniform grid

| Pattern | Click distribution | Position-bias capture | Implementation cost |
|---|---|---|---|
| **Featured #1 + 4 grid** | Concentrates clicks on top slot | Captures the 53–87% position-bias premium (Collins 2018) | Low |
| Uniform 5-card grid | Distributes clicks across slots | Lets position bias work against random ordering | Low |
| Carousel | Hides ranks 3+ behind a click | Position bias on slot 1 only | Higher (JS, mobile UX) |
| List with thumbnails | Vertical, traditional | Position bias on slot 1; lower visual weight | Lowest |

Featured-first chosen — it's the highest-performing pattern in the cited research and is no more complex to build than the uniform grid.

## C. Sizing & Cost Model

### C.1 Embedding cost per site (May 2026 pricing)

Working assumption: 1,000 tokens per post (covers most blog content; longer posts truncated to the model's max input). At $0.02 / 1M tokens for `text-embedding-3-small`:

| Corpus size | Tokens (≈) | One-time index cost |
|---|---|---|
| 200 posts | 200K | $0.004 |
| 1,000 posts | 1M | $0.02 |
| 5,000 posts | 5M | $0.10 |
| 10,000 posts | 10M | $0.20 |

Ongoing cost: only when a post is created or edited. A site publishing 5 posts/week incurs ~$0.0001/week — effectively free.

This is the number surfaced in the setup wizard via FR-9.

### C.2 Storage cost (postmeta)

Embedding storage as base64-encoded `float32`: 1536 dims × 4 bytes = 6,144 bytes raw; ~8,192 bytes base64-encoded ≈ 6KB after WP storage overhead. (Note: brief mentions "~6KB/post vs ~15KB JSON" — same ballpark.)

| Corpus size | Embedding storage | Related-list storage |
|---|---|---|
| 200 posts | ~1.2MB | ~10KB |
| 5,000 posts | ~30MB | ~250KB |
| 10,000 posts | ~60MB | ~500KB |

At 5,000 posts: ~30MB of postmeta. Well under the 50MB NFR-HOST-4 target.

### C.3 Cosine compute cost

Pure-PHP cosine over `float32` arrays at 1,536 dims:
- Naïve PHP implementation: ~10 µs per pair on commodity hardware.
- For 5,000 posts pairwise: 5,000 × 5,000 / 2 = 12.5M pairs × 10 µs = ~125 seconds.

This is above the 60-second working target in PRD §FR-4 / Open Question #5. Optimizations available:
1. **Top-K with early termination** during pairwise comparison (skip pairs below running threshold).
2. **Per-post incremental refresh** — only recompute for posts whose embedding changed since last tick.
3. **SIMD via PHP extension** (e.g., `ext-vector` if available) — host-dependent.
4. **Batched matrix multiplication** via `mysql-vector` or similar — if added to dependencies.

Architecture phase to pick the optimization mix that hits the budget. Incremental refresh alone likely closes the gap for steady-state operation; bulk recompute is the constrained path.

## D. Detailed Persona — Sarah (1,200-post niche blogger)

Used in UJ-1. Sarah is the load-bearing persona for v1 — her experience defines whether the install-to-value path lands.

- **Site type:** niche blog, single-author, 4–8 years of accumulated content. ~1,200 published posts spanning 8–12 categories.
- **Hosting:** managed (Kinsta, WP Engine, Pressable, or similar). Pays ~$30–60/mo.
- **Income:** site generates $500–3,000/mo via display ads (Mediavine, Raptive, AdThrive) or affiliate links. Site is real income, not a hobby.
- **Tech comfort:** installs and configures plugins regularly. Knows what an API key is. Has not personally written PHP, but has copy-pasted CSS into theme customizer.
- **Pain pattern:** noticed Jetpack Related Posts surfacing the same 3 recent posts on every page. Tried YARPP at some point and either disabled it (host audit) or found it slow. Currently running either Jetpack/Same Category or nothing.
- **Buying trigger:** searches "best related posts plugin 2026" or "alternative to Jetpack related posts" or "WP Engine compatible related posts" on Google or WP.org directly.
- **Time budget for install:** 10 minutes max before they bounce to the next plugin search result.

Marketing copy and onboarding wizard tone calibrate to Sarah, not to the agency operator. Agency operators are secondary and largely self-serve — if Sarah's install path works, theirs does too.

## E. Host Pre-Approval Process

PRD §10.3 and NFR-HOST-1 commit to pre-launch host outreach. The brief specifies the process steps; reproduced here so the marketing/launch workflow has a single source.

### E.1 Sequence

1. **Publish the reproducible performance benchmark** on the marketing site. Includes: TTFB delta script, queries-added-per-pageview test, memory-peak measurement, source code anyone can run.
2. **Build the architecture document.** One paragraph + simple diagram covering: `save_post → background embedding via WP-Cron → postmeta cache → 2 indexed queries per pageview, zero external HTTP on render`.
3. **Email each target host's engineering contact.** Targets: WP Engine (highest visibility), Kinsta, Pressable, Pantheon, GoDaddy Managed, SiteGround. Email outline:
   - Subject: *SemanticPosts — semantic related-posts plugin built for managed-host compatibility — review request*
   - Body: 3 paragraphs. (1) What it does. (2) Why it doesn't trigger your audit (point to architecture doc + benchmark URLs). (3) Ask: would you review for inclusion on your "compatible" list?
4. **Ship a built-in audit surface.** The observability panel (FR-10) lets a host support engineer verify the claims in 30 seconds inside any test install. Visibility is a deliberate audit-friendliness move, not just user-facing instrumentation.
5. **Update WP.org listing and marketing site** with each host pre-approval as it lands.

### E.2 Residual risk

If a host adds the plugin to a disallow list anyway: direct engineering-contact outreach with architecture doc and benchmarks. YARPP went through the same loop in 2022 and was eventually unbanned. The architecture genuinely doesn't trigger the standard ban concerns; the conversation is "show us the evidence," and the evidence exists.

## F. Disposable-Build Rationale

The PRD's 1-week MVP scope and 90-day kill criterion are not conservative defaults — they are a deliberate portfolio strategy.

The product owner ships ~1-week MVPs across a portfolio of disposable bets. Per-attempt revenue target: ~$5k captured over 6 months. Discipline: comfortable abandoning the product when incumbents catch up or kill criteria miss. This is documented in the user's working-style memory and applies to every product they pursue.

This shapes several PRD-level decisions reviewers might otherwise flag:

- **No deep moat investment.** Architecture is the moat at the technical level, not the strategic level. If AIOSEO ships semantic relatedness in Q3, that's expected — by then the plugin has captured 6 months of installs and reviews.
- **No premature monetization.** Pro tier ships only if Day-90 milestones hit. Otherwise the project is abandoned and the next idea begins.
- **Hard kill criterion.** SM-1 (500 installs by Day 90) is not a goal; it is the bar. Below it, the project ends. No "give it one more month."
- **Out-of-scope items are not "v1.1 commitments."** They are options. v1.1 may never happen because the project may be dead by then.

Reviewers and downstream workflow agents should treat the kill criterion as load-bearing. Scope expansion in epics/stories that pushes the build beyond ~1 week violates the operating mode and should be challenged.

## G. Competitive Map (Detailed)

PRD §1 and §2.3 reference the competitive landscape compactly. Full map below; reflects research-subagent corrections from 2026-05-20.

| Plugin | Owner / Source | Architecture | Status (May 2026) | Threat to SemanticPosts |
|---|---|---|---|---|
| YARPP | WP.org | Brute-force MySQL relevance, per-request | Optimized 2022; unbanned by WP Engine; large install base | **Medium** — entrenched; architecturally old; positioning angle is "per-request cost vs. cached" |
| Contextual Related Posts | WP.org | Brute-force MySQL, per-request | **Still on WP Engine disallow list** (verified) | **Low** — the pain is the wedge |
| Similar Posts | WP.org | Brute-force MySQL, per-request | **Still on WP Engine disallow list** (verified) | **Low** — same as above |
| Dynamic Related Posts | WP.org | Brute-force, per-request | **Still on WP Engine disallow list** (verified) | **Low** |
| SEO Auto Links & Related Posts | WP.org | Per-request | **Still on WP Engine disallow list** (verified) | **Low** |
| Jetpack Related Posts | Automattic | Cloud-based (Elasticsearch-style) + category-driven | Bundled with Jetpack | **Medium** — bundled distribution; the "visibly poor suggestions" Sarah persona is escaping |
| Same Category Posts | WP.org | Tag/category matching | Stagnant | **Low** — same as above |
| **WPVDB** | github.com/Jameswlepage (owner correction 2026-05-20) | Native MySQL vectors, semantic | 11 stars; release v1.0.16 on 2026-05-19 (active); positioned as "Native Vector Database for WordPress" — developer/infra tool | **Medium** — architecturally adjacent (same idea) but consumer-related-posts framing is open. Low adoption (11 stars) means it has not occupied the consumer wedge. **Originally framed as "highest threat" in earlier brief; recalibrated to "most technically credible adjacent project."** |
| AI Vector Search Semantic | WP.org | Requires Supabase | <10 active installs; WooCommerce-focused | **Low** |
| SemantiQ Search | GitHub | Requires Qdrant | GitHub only | **Low** |
| OC3 Semantic box | WP.org | OpenAI + Pinecone | WP.org listing | **Low** |
| Typesense WP Vector | WP.org | Requires self-hosted Typesense | WP.org listing | **Low** |
| **AI Engine (jordy-meow)** | WP.org | Embedding / vector features positioned partly as related content | Active 2026 | **New entrant — added 2026-05-20.** Positioned as AI assistant / chatbot / search infrastructure; not direct competitor for "consumer related posts," but reviewers should note its existence. |
| **AI Search** | WP.org | Embedding-based site search | Active 2026 | **New entrant — added 2026-05-20.** Search positioning, not related posts. Adjacent. |
| AIOSEO / Yoast / Rank Math / Jetpack (semantic surface) | Commercial SEO suites | Would ship in a quarterly release | **No semantic-relatedness feature shipped or roadmapped as of 2026-05-20** (verified across all four) | **Medium** — they may enter; in disposable-build mode this is expected and bounded. |

### G.1 Positioning summary

- **Against brute-force plugins (YARPP, CRP, Similar Posts, DRP):** "Faster *and* host-safe."
- **Against category-matching plugins (Jetpack, Same Category):** "Actually relevant."
- **Against external-infra embedding plugins (WPVDB, SemantiQ, AI Vector Search, OC3, Typesense):** "Self-contained — no Qdrant, no Pinecone, no Supabase."
- **Against AI Engine / AI Search:** "Built for related posts, not search or chat. One thing well."
- **Against big-SEO suites:** "Pure-play. Not bundled with the 30 other features you don't need."

## H. Research Bibliography

Supporting research for PRD §1 (positioning), UJ-1 (install path), §4.3 (display), §7 (success metrics), §11 (Why Now), Aesthetic and Tone §14, and the Featured-first decision.

- **Collins et al., 2018** — *Click Models for Web Search and Their Applications to IR.* Position bias study across 10M recommendations; #1 slot receives 53–87% more clicks than expected. [arxiv.org/abs/1802.06565](https://arxiv.org/abs/1802.06565)
- **Beierle et al., 2019** — CTR study across 41M recommendations; sweet spot 5–6 items, 0.41% CTR at 1 item dropping to 0.09% at 15. [link.springer.com/article/10.1007/s00799-019-00270-7](https://link.springer.com/article/10.1007/s00799-019-00270-7)
- **Center for Media Engagement (UT Austin)** — End-of-article links generate +55% clicks vs. mid-article (mobile +61%, phablet +82%). Verified 2026-05-20. [mediaengagement.org/research/links/](https://mediaengagement.org/research/links/)
- **Nielsen Norman Group — Related Content Boosts Pageviews** — End-of-article is the position of maximum reader receptivity. [nngroup.com/articles/related-content-pageviews/](https://www.nngroup.com/articles/related-content-pageviews/)
- **Nielsen Norman Group — Recommendation Guidelines** — Pop-ups, exit-intent, pre-content classified as "needy patterns" that degrade UX. [nngroup.com/articles/recommendation-guidelines/](https://www.nngroup.com/articles/recommendation-guidelines/)
- **Mida — A/B Testing Below the Fold** — Scroll-triggered mid-content injection at ~60% read depth yielded +25% CTR. Cited as v1.1 differentiation lever. [mida.so/blog/ab-test-below-the-fold](https://www.mida.so/blog/ab-test-below-the-fold)

## I. Pricing Reference (deferred Pro tier)

PRD §12 defers Pro. Provisional structure preserved here so it does not have to be re-derived when Pro is built (or so that this content can be discarded along with the project if Day-90 misses).

- **Free:** Up to 200 indexed posts, OpenAI provider only, default templates. Distributed via WP.org.
- **Pro ($49/year, single site):** Unlimited posts, additional providers (Voyage, Cohere), self-hosted model option, premium templates, Gutenberg block, priority support, custom post-type indexing.
- **Pro Multi ($129/year, up to 5 sites):** Same as Pro plus multi-site license.

Pricing anchors: Link Whisper ($97/yr), YARPP-adjacent expectations. Lifetime/AppSumo deferred to v1.5 if ever.

## J. Distribution and Marketing Tactics

Beyond the generic "WP.org organic funnel + SEO" framing in PRD §1 and §11, the brief commits to specific tactical angles that downstream marketing work should preserve. Captured here so a marketing-side task list can lift them directly.

### J.1 Named SEO content angle

The marketing site ships a specific high-intent SEO article at launch: **"5 related-posts plugins still banned by WP Engine in 2026 — and what to use instead"** (or equivalent updated headline). The named-and-shamed plugins are the current WP Engine disallow-list entries: Contextual Related Posts, Similar Posts, Dynamic Related Posts, and SEO Auto Links & Related Posts. (YARPP is NOT on this list — it was removed in April 2022 — and the article should explicitly say so to preempt informed pushback.)

This article serves two purposes simultaneously:

1. **Audience-acquisition.** Search intent on "WP Engine banned plugin" or "[plugin name] WP Engine" is high — these are site owners who just discovered their related-posts plugin doesn't work and are looking for alternatives in real time.
2. **Positioning.** Every reader who finds the article via search arrives already aligned with SemanticPosts' wedge ("host-safe, semantic, self-contained").

This is **NFR-MKT-4** in the PRD. It is a launch-day deliverable, not a post-launch optimization.

Update cadence: refresh the article quarterly against WP Engine's current public disallow list. If any of the four named plugins gets unbanned (as YARPP did in 2022), revise the article and the framing.

### J.2 Listicle outreach gated on 20+ five-star reviews

Outreach to third-party "best related-posts plugins for WordPress" listicle authors — WPBeginner, Kinsta blog, Themeisle, WP Marmite, equivalents — **does not begin until the plugin has 20+ five-star reviews on WordPress.org.** Earlier outreach burns the relationship: listicle authors do not take unproven plugins seriously and adding the plugin to a "best of" piece based on potential is not a thing they do. This gate is operational discipline, not a hard rule.

This delay does not block SM-4 (third-party listing by Day 90) — it shapes when outreach begins. Working timeline:

- **Day 0 (launch):** plugin live on WP.org; named SEO article (J.1) live on marketing site.
- **Day 7–30:** organic discovery via WP.org search and the named SEO article drives early installs and reviews. SM-2 progress monitored.
- **Day 30–45 (assuming review accumulation):** plugin has 20+ five-star reviews. Outreach to listicle authors begins.
- **Day 45–90:** listicle pickup (SM-4).

If 20+ reviews haven't materialized by Day 45, outreach is paused and the gap is diagnosed: install funnel, conversion to install, install-to-review conversion. Pushing out a "best of" pitch with 4 reviews is not the fix.

### J.3 In-product upgrade nudges

None in v1. The free-only positioning rules out in-product upsell. The Settings page may include a one-line "Stay informed about Pro" link to the marketing site, but **no modal, no banner, no dismissable notice.** Discipline: every admin-UI interruption is a SM-C1 ("too many features / complex settings") risk.

If Pro launches post-Day-90, in-product upgrade prompts are designed in the Pro PRD, not retrofitted into v1.

### J.5 Secondary SEO articles — "Alternatives to" comparison content

In addition to the named "5 plugins banned by WP Engine" angle (J.1, NFR-MKT-4), the marketing site publishes two **alternatives-to** comparison articles at launch:

- **"YARPP Alternatives 2026"** — targets readers searching for a replacement to YARPP. Acknowledges YARPP was unbanned by WP Engine in 2022 (preempts informed pushback), but positions SemanticPosts on the basis of (a) semantic vs. brute-force relevance computation, (b) precompute-then-cache vs. per-request workload, and (c) configurable Ranking Modes that YARPP does not offer.

- **"Jetpack Related Posts Alternatives 2026"** — targets readers who find Jetpack's category-driven recommendations visibly weak. Positions SemanticPosts on the basis of cross-category semantic relatedness (Brief §Solution: *"finds cross-category connections category matching misses entirely"*) and on independence from the broader Jetpack bundle.

Both articles are launch-day deliverables alongside J.1 (NFR-MKT-4). They target high-intent comparison-shopping search and produce arrivals already aligned with the SemanticPosts positioning. Refreshed quarterly alongside the J.1 article.

### J.4 Outreach contact paths (per host)

PRD Open Question #9 acknowledges these aren't yet identified. Working list to research before SM-3's clock starts:

- **WP Engine** — host-partners or developer-relations contact via wpengine.com/partners or @WPEngine on X.
- **Kinsta** — partners@kinsta.com or via Mia Honaker / developer-relations team.
- **Pressable** — partners@pressable.com.
- **Pantheon** — partners@pantheon.io.
- **GoDaddy Managed (formerly WP Engine Pro tier; now part of GoDaddy)** — pro.partners@godaddy.com.
- **SiteGround** — partners@siteground.com.

Each email follows the addendum §E.1 step 3 outline: 3 paragraphs (what it does / why it doesn't trigger your audit / review request). The 4-week SM-3 timeout starts when each email is sent. Sending one then waiting on the next is wrong — send all six in the same batch on the same day, then count from that day.
