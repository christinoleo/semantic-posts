---
stepsCompleted: [1, 2, 3]
inputDocuments:
  - _bmad-output/planning-artifacts/project-brief.md
  - _bmad-output/planning-artifacts/prds/prd-semanticPosts-2026-05-20/prd.md
  - _bmad-output/planning-artifacts/prds/prd-semanticPosts-2026-05-20/addendum.md
  - _bmad-output/planning-artifacts/architecture.md
  - docs/CONTEXT.md
  - docs/adr/0001-indexable-text-composition.md
  - docs/adr/0002-embedding-regeneration-triggers.md
  - docs/adr/0003-storage-postmeta-derived.md
  - docs/adr/0004-crawler-based-indexing.md
  - docs/adr/0005-no-per-embedding-model-versioning.md
  - docs/adr/0006-recommendation-ranking-modes.md
  - docs/adr/0007-rendering-contract.md
  - docs/adr/0008-phased-cold-start-knn.md
workflowType: 'epics-and-stories'
project_name: 'semanticPosts'
date: '2026-05-20'
---

# semanticPosts - Epic Breakdown

## Overview

This document provides the complete epic and story breakdown for semanticPosts (working title), decomposing the requirements from the PRD and Architecture into implementable stories. UX Design was deliberately skipped during planning (decision-log: brief's UX rationale was deeper than the UX skill would produce for this MVP scope).

## Requirements Inventory

### Functional Requirements

- **FR-1** Per-post indexing on save (hash-diff triggered). On `save_post`, compute Indexable Text hash; mark dirty only if changed. Hourly cron drains dirty queue. First-publish bypass for immediate freshness. `wp_trash_post` / password / status-away cleans up `_sp_*`. (ADR-0002)
- **FR-2** Bulk index. One-time admin action processing every Indexable Post in batches of 50/tick with 1s pause and 1 req/sec rate limit. Resumable across cron ticks. Cost preview before confirm. 1000 posts <2 min on Reference env.
- **FR-3** API failure handling. Exponential backoff retry on 5xx/429; 3 strikes → failed flag in observability panel + manual "Retry failed posts" action.
- **FR-4** Crawler-based incremental update. On embedding regeneration: candidate set from `_sp_related` ∪ neighbors-of-neighbors ∪ `_sp_inbound` ∪ random sample. Score, top-K, propagate to affected neighbors. O(K²) per save, independent of corpus size. <100ms per insert on Reference env. (ADR-0004)
- **FR-4a** Ranking Mode selection. Three modes: Most Relevant (pure cosine, default), Fresh-first (recency-weighted), Diverse Mix (MMR). Featured #1 = highest cosine across all modes. Mode change takes effect on next crawler update; no reindex. (ADR-0006)
- **FR-4b** Cold start (bulk index bootstrap). Phased per ADR-0008: brute-force pairwise for first 200 posts; graph-traversal kNN walk (visit budget 300, L=5 entry points) for posts beyond bootstrap. Batched 50/tick, resumable, memory peak ~8.6MB. Category-fallback during for not-yet-indexed posts.
- **FR-5** Manual reindex and verification. Admin actions: "Reindex all" (wipes `_sp_*`, triggers cold start), "Retry failed posts", "Run verification pass now". Weekly verification pass samples M=20 random posts, computes MRD vs brute-force top-5; admin notice if MRD ≥ 1.5.
- **FR-6** Auto-injection display. Renders related-posts section after `the_content` on `is_single()` views only. De-duplicates with `[semantic_posts]` shortcode. Silent category-fallback when `_sp_related` empty.
- **FR-7** Shortcode display. `[semantic_posts]` renders the same template at shortcode location. Optional `count` attribute (3–10). Multiple invocations in one post = first wins, subsequent are no-ops.
- **FR-8** Featured-first template (Rendering Contract). Default K=5 items (range 3–10). #1 = Featured card (`<article class="semantic-posts-featured">`, large thumbnail, excerpt). Items 2–K in grid (`<ul class="semantic-posts-grid">`). HTML structure per ADR-0007. Theme override at `{theme}/semantic-posts/related-posts.php`. Filters: `semantic_posts_heading_text`, `_excerpt_length`, `_item_classes`, `_render_html`, `_template_path`, `_thumbnail_size`, `_min_score`. Actions: `_before_render`, `_after_render`. Quality-bounded mode (opt-in) drops items below `score_threshold`.
- **FR-9** Settings UI. Single admin page (`Settings → SemanticPosts`): API key (masked, validated), embedding model dropdown (default `text-embedding-3-small`), post types multi-select (default `post`; advisory note for commerce types), related-post count (3–10, default 5), Ranking Mode radio, Quality-bounded toggle (+ `min_items`, `score_threshold`), Display mode radio, Cron frequency select, Cost preview (live). One autoloaded option ≤1KB. Model change triggers wipe-and-reindex confirmation modal.
- **FR-10** Observability panel. Admin panel showing: 24h embedding API calls + cost estimate; posts in queue / failed (+ "Retry failed" button); last cron tick timestamp + outcome; last verification pass timestamp + MRD; render-path queries added (24h, should be ≤2/pageview); peak indexing memory (24h, MB); recent activity log tail (~50 events); "Run indexing now" button; graceful-restore banner when ≥5% of Recommendable Posts lack embeddings.
- **FR-11** WP-CLI commands: `wp semantic-posts index`, `reindex`, `process-dirty`, `verify`, `retry-failed`, `status` (with `--format=json` on status). Non-zero exit codes on failure.
- **FR-12** Clean uninstall. Removes all `_sp_*` postmeta + settings option + `_sp_state` option on uninstall. Deactivation preserves data (re-activation resumes from existing index).

### Non-Functional Requirements

**Performance (the core promise):**
- **NFR-PERF-1** TTFB delta < 5ms vs no-plugin baseline on a clean 5,000-post install (Reference env).
- **NFR-PERF-2** Exactly 2 added queries per pageview: 1 `get_post_meta` for `_sp_related`, 1 `WP_Query` for related IDs.
- **NFR-PERF-3** Zero HTTP requests during request rendering. Embedding provider called only from WP-Cron/CLI.
- **NFR-PERF-4** Added memory footprint < 1MB per request. Embeddings never loaded at render.
- **NFR-PERF-5** Page-cache compatible. Identical HTML for identical post; verified against WP Rocket, W3 Total Cache, LiteSpeed Cache, WP Engine, Kinsta, Cloudflare APO.

**Host compatibility (the positioning):**
- **NFR-HOST-1** Soft gate: ≥1 host pre-approval before launch with 4-week timeout. If timeout fires, launch without compat-list claim, continue outreach post-launch.
- **NFR-HOST-2** Not on any host disallow list at launch day.
- **NFR-HOST-3** Object-cache compatible (Redis/Memcached) without manual configuration.
- **NFR-HOST-4** Backup-safe. Binary-encoded embeddings; `semantic_posts_exclude_from_backup` filter; postmeta added at 5k posts <50MB.
- **NFR-HOST-5** Autoloaded options: exactly 1, <1KB total.

**Indexing reliability:**
- **NFR-IDX-1** Bulk-index 1000 posts < 2 min on Reference env.
- **NFR-IDX-2** Never trips PHP memory limit at `WP_MEMORY_LIMIT=256M`. Halts at 80% and resumes.
- **NFR-IDX-3** Resumable. Process kill mid-batch resumes from last completed batch on next tick.
- **NFR-IDX-4** WP-Cron-disabled fallback. All operations work via CLI + admin "Run now" button.
- **NFR-IDX-5** API failure tolerance. Exponential backoff; three-strike retry queue; never crashes WordPress.
- **NFR-IDX-6** Log discipline. <10 lines/day in `debug.log` under normal operation.

**Suggestion quality:**
- **NFR-QUAL-1** Baseline beat. 100-post curated test corpus, blind comparison vs category-matching baseline. Pass: ≥3 of 5 SP suggestions reasonable AND SP "yes rate" exceeds baseline.
- **NFR-QUAL-2** Cross-category discovery 20–50% of top-5 items.
- **NFR-QUAL-3** Zero "completely unrelated" (cross-domain) suggestions in 50-post manual review.
- **NFR-QUAL-4** Featured #1 is the highest-cosine match across all modes (no recency/diversity shuffle).

**Onboarding:**
- **NFR-ON-1** First-run setup < 5 min on a fresh install.
- **NFR-ON-2** PHP 8.0+ support. No 8.2+-only syntax.
- **NFR-ON-3** MySQL 5.7+ / MariaDB 10.3+ support. No MySQL 8 VECTOR dependency.
- **NFR-ON-4** Honest cost preview before any spending action.

**WordPress.org review:**
- **NFR-WPORG-1** Passes WP.org review on first submission (sanitization, nonces, escaping, no eval/base64_decode for code execution, GPL-compatible).
- **NFR-WPORG-2** Zero PHP notices/warnings/errors with `WP_DEBUG=true`.
- **NFR-WPORG-3** Zero JS console errors in admin pages.
- **NFR-WPORG-4** i18n-ready. All user-facing strings translatable; `.pot` file shipped.
- **NFR-WPORG-5** `readme.txt` meets WP.org format (tested-up-to, stable tag, screenshots, FAQ).
- **NFR-WPORG-6** Listing copy includes "Tested with WP Engine, Kinsta, SiteGround, Cloudways, Pressable" line once any host pre-approves.

**Marketing site (the conversion gate — out of plugin scope but tracked):**
- **NFR-MKT-1** Reproducible performance benchmark published with code anyone can run.
- **NFR-MKT-2** One-paragraph architecture doc + simple diagram on marketing homepage.
- **NFR-MKT-3** Host-compatibility list visible above the fold once any host pre-approves (deferred if SM-3 4-week timeout fires).
- **NFR-MKT-4** Named SEO listicle ("5 related-posts plugins still banned by WP Engine in 2026") shipped at launch.

### Additional Requirements

Derived from Architecture (architecture.md and ADRs 0001–0008):

- **AR-1 Starter template (FIRST IMPLEMENTATION STORY).** Plugin initialized via `wp scaffold plugin semantic-posts` + `wp scaffold plugin-tests semantic-posts` + Composer PSR-4 overlay (namespace `SemanticPosts\` rooted at `src/`). Required before any other story.
- **AR-2** Reference environment Docker fixture (`docker/Dockerfile` + `docker-compose.yml`) reproducing 1 vCPU, 1GB RAM, PHP 8.0, MySQL 5.7, `WP_MEMORY_LIMIT=256M`, no opcache tuning, no object cache. Required for NFR-IDX-1, NFR-PERF-1, and NFR-MKT-1 benchmarks.
- **AR-3** PHP_CodeSniffer with WPCS ruleset configured in `phpcs.xml.dist`. `src/` exempted from `WordPress.Files.FileName` rule (PSR-4 file naming). CI blocks on phpcs failure.
- **AR-4** PHPUnit + WordPress test framework via `wp scaffold plugin-tests`. Tests mirror `src/` 1:1 under `tests/`.
- **AR-5** AES-256-CBC + `AUTH_SALT`-derived key for API-key encryption-at-rest (`\SemanticPosts\Security\ApiKeyStorage`). Random per-value IV stored alongside ciphertext.
- **AR-6** Single registered cron event `semantic_posts_cron_tick` → `\SemanticPosts\Indexing\TickProcessor::run()`. Work-stealing drain: cold-start batch + dirty queue + verification pass routed through one entry point.
- **AR-7** Single non-autoloaded option `_sp_state` stores cold-start phase, last_processed_id, verification last_run/next_due/last_mrd, dirty_queue_count. Does NOT count against NFR-HOST-5 (not autoloaded).
- **AR-8** Centralized logging via `\SemanticPosts\Logging::warn/error/info`. Format: `[SemanticPosts][LEVEL] {message} {context json}`. `info` gated behind `SEMANTIC_POSTS_VERBOSE` constant.
- **AR-9** `Embeddings\Provider` interface with classified exceptions (`RetryableException`, `FatalException`). OpenAI is sole v1 implementation. Retry/backoff lives in `EmbedJob`, not in Provider.
- **AR-10** Postmeta single-writer invariant. `_sp_embedding` codec owned by `Vector`; `_sp_related` + `_sp_inbound` owned by `Crawler`; `_sp_text_hash` + `_sp_dirty` owned by `HashDiffDetector`. Other code reads through these classes.
- **AR-11** All `add_action`/`add_filter` registrations centralized in `Bootstrap::registerHooks()`. Subsystems expose methods; Bootstrap wires them.
- **AR-12** Boundary discipline (mandatory at every entry point): nonce check + cap check + sanitize + escape + i18n with text domain `semantic-posts`. Anti-Patterns table in architecture.md is the WP.org review pre-flight.
- **AR-13** Empirical Validation Registry (EV-01 through EV-15) — 15 constants/heuristics with revisit triggers documented in architecture.md. Filter hooks exposed where revision is most likely. Sprint retros walk this list at Day 30 / Day 90.
- **AR-14** Vanilla JS for admin UI (no jQuery dependency). Native `fetch()` for AJAX. `wp_ajax_semantic_posts_run_indexing_now`, `_retry_failed`, `_run_verification_now` with `wp_nonce` + `current_user_can('manage_options')`.
- **AR-15** GitHub Actions CI (`.github/workflows/ci.yml`): `composer install --no-dev` + `composer install` + `phpcs` + `phpunit` + WP plugin asset readiness check, all blocking.
- **AR-16** Benchmark workflow (`.github/workflows/benchmark.yml`) runs nightly, publishes to `benchmarks/` branch consumed by marketing site (NFR-MKT-1).
- **AR-17** Deployment: `composer install --no-dev && zip` produces WP.org submission zip excluding `tests/`, `docker/`, `.github/`.

### UX Design Requirements

_Not applicable for v1. UX Design skill was deliberately skipped during planning (decision-log 2026-05-20): the brief's UX rationale — Collins 2018, Beierle 2019, NN/G, Mida — was deeper than the UX skill would produce for a 1-week MVP. The Rendering Contract (ADR-0007) covers the only UI surface that needs design specification (the related-posts widget). Admin pages use WordPress's Settings API + observability panel HTML; visual design is conventional and not on the critical path._

### FR Coverage Map

| FR | Epic | Role in epic |
|---|---|---|
| FR-1 (hash-diff trigger) | Epic 2 | Triggers re-embedding on save |
| FR-2 (bulk index) | Epic 2 | Cold-start kickoff from settings UI |
| FR-3 (API failure handling) | Epic 2 | Retry queue + failed flag |
| FR-4 (crawler warm path) | Epic 2 | Similarity graph maintenance on updates |
| FR-4a (Ranking Mode select) | Epic 2 | Mode selection wired into Settings |
| FR-4b (cold start) | Epic 2 | Phased bootstrap + graph-walk per ADR-0008 |
| FR-5 (manual reindex + verification) | Epic 3 | Admin actions + weekly verification pass |
| FR-6 (auto-injection) | Epic 1 | `the_content` filter render |
| FR-7 (shortcode) | Epic 1 | `[semantic_posts]` parser |
| FR-8 (featured-first template) | Epic 1 | Rendering contract (ADR-0007) |
| FR-9 (settings UI) | Epic 1 (display fields) + Epic 2 (API key, model, ranking, count, quality, cron freq) | Progressive enhancement |
| FR-10 (observability panel) | Epic 3 | 24h counters, activity log, "Run now" button |
| FR-11 (WP-CLI commands) | Epic 3 | 6 commands mirroring admin actions |
| FR-12 (clean uninstall) | Epic 1 | `includes/uninstall.php` final version |
| NFR-HOST-4 (backup-exclusion filter) | Epic 1 | Story 1.12 — `semantic_posts_exclude_from_backup` |
| NFR-QUAL-1/2/3 (quality eval gate) | Epic 4 | Story 4.6 — pre-launch blind comparison |
| AR-13 (EV registry surface) | Epic 3 | Story 3.6 — read-only admin table of EV-01..EV-15 |
| AR-17 (deployment zip) | Epic 4 | Story 4.7 — `composer build` reproducible artifact |

NFRs distribute across epics: PERF (Epic 1, validated under category-fallback render), HOST (Epic 1 boundary + Epic 4 listing copy), IDX (Epic 2), QUAL (Epic 2 algorithm + Epic 3 verification), ON (Epic 2 setup wizard), WPORG (enforced throughout, final pass Epic 4), MKT (Epic 4 benchmark workflow; site copy out of plugin scope).

## Epic List

### Epic 1: Foundation & First-Render Path (Category-Fallback Mode)

Plugin scaffolds cleanly, activates without errors, and renders the related-posts widget on single-post pages using category fallback. No API key required to test this version end-to-end. Minimal settings page exposes post-type multi-select and display-mode toggle. Theme override path works. Uninstall removes all plugin state. Backup-exclusion filter is exposed. The entire render-path contract (NFR-PERF-1..5) is validated on this version alone.

**FRs covered:** FR-6, FR-7, FR-8, FR-9 (display-related fields only), FR-12. **NFR-HOST-4 backup filter** also covered (Story 1.12).
**Architecture requirements addressed:** AR-1, AR-2, AR-3, AR-4, AR-10 (CI sniff), AR-11, AR-12 (CI sniff), AR-14 (CI check).

### Epic 2: Semantic Indexing Pipeline

Site owner adds an OpenAI API key and triggers bulk indexing; embeddings are generated background-rate-limited, the crawler builds the Similarity Graph using ADR-0008's phased cold-start (brute-force bootstrap ≤200 posts, graph-traversal kNN beyond), and related-posts widgets transition from `data-sp-source="category-fallback"` to `data-sp-source="semantic"`. `save_post` to a published post triggers hash-diff re-embedding via WP-Cron. Realizes UJ-1 (install → first related posts) and UJ-2 (publish → populate without intervention).

**FRs covered:** FR-1, FR-2, FR-3, FR-4, FR-4a, FR-4b, FR-9 (API key, embedding model, related-post count, Ranking Mode, Quality-bounded, cron frequency).
**Architecture requirements addressed:** AR-5, AR-6, AR-7, AR-8, AR-9, AR-10.

### Epic 3: Operational Surface (Verify, Observe, CLI)

Site owner has full audit visibility into plugin health via the observability panel (24h embedding API calls + cost, posts in queue / failed, last cron tick, last verification pass + MRD, render-path queries per pageview, peak indexing memory, recent activity log, graceful-restore banner). Admin actions available: Reindex all, Retry failed, Run verification now, Run indexing now. Weekly verification pass samples M=20 posts, computes MRD vs brute-force top-5, surfaces an admin notice if MRD ≥ 1.5. EV registry values (algorithm tuning constants) are surfaced read-only in the panel. WP-CLI commands mirror admin operations for hosts with WP-Cron disabled. Realizes UJ-4 (owner audit) and UJ-5 (host engineer audit).

**FRs covered:** FR-5, FR-10, FR-11. **Architecture requirements addressed:** AR-13 (EV registry surface).

### Epic 4: Launch Readiness (WP.org Submission + Benchmark)

Plugin passes `wp plugin check` (official WP.org pre-submission tool). `readme.txt` finalized with screenshots, FAQ, and the host-compatibility copy line. `.pot` file regenerated covering the full final string set. Benchmark workflow (`.github/workflows/benchmark.yml`) runs nightly, publishes Reference-environment performance data consumable by the marketing site (NFR-MKT-1). Pre-launch quality evaluation (NFR-QUAL-1/2/3) runs as a launch gate. Deployment zip script produces the canonical WP.org artifact reproducibly. CI blocks pushes on phpcs + phpunit + plugin-check failures. Marketing-site deliverables (NFR-MKT-2/3/4) are referenced but out of plugin scope.

**FRs covered:** N/A directly; closes NFR-WPORG-1/2/3/4/5/6, NFR-MKT-1, NFR-QUAL-1/2/3.
**Architecture requirements addressed:** AR-15, AR-16, AR-17.

## Epic 1: Foundation & First-Render Path (Category-Fallback Mode)

Plugin scaffolds cleanly, renders the related-posts widget on single-post pages using category fallback. The entire render path contract (NFR-PERF-1..5) is validated under this epic alone — no API spend required to test.

### Story 1.1: Scaffold plugin with WP-CLI + Composer PSR-4

As a developer,
I want a canonical WP plugin skeleton with Composer-based PSR-4 autoload wired in,
So that all subsequent stories build on a structure WP.org reviewers expect (NFR-WPORG-1).

**Acceptance Criteria:**

**Given** an empty working directory with WP-CLI installed,
**When** I run `wp scaffold plugin semantic-posts --plugin_name="SemanticPosts" --activate=false`,
**Then** the plugin directory is created with `semantic-posts.php`, `readme.txt`, `includes/`, `assets/`, `languages/`.

**Given** the scaffolded plugin,
**When** I run `wp scaffold plugin-tests semantic-posts`,
**Then** a `tests/` directory with PHPUnit bootstrap and a `bin/install-wp-tests.sh` script is added.

**Given** the scaffolded plugin,
**When** I run `composer init --type=wordpress-plugin --no-interaction` and add `"autoload": {"psr-4": {"SemanticPosts\\": "src/"}}`,
**Then** `composer install` produces a working autoloader and `src/` is recognized as the PSR-4 root.

**Given** the main plugin file `semantic-posts.php`,
**When** WordPress activates the plugin,
**Then** the file loads Composer autoload, defines `SEMANTIC_POSTS_VERSION`, `SEMANTIC_POSTS_DIR`, `SEMANTIC_POSTS_URL` constants, and instantiates `\SemanticPosts\Bootstrap` (stub OK at this story).

### Story 1.2: Add Reference environment Docker fixture

As a developer,
I want a reproducible Docker fixture matching the Reference environment from PRD §9.5,
So that performance and memory NFRs can be tested against the canonical hardware tier (AR-2, NFR-IDX-1, NFR-PERF-1).

**Acceptance Criteria:**

**Given** `docker/Dockerfile` based on PHP 8.0 + Apache + MySQL 5.7 with `WP_MEMORY_LIMIT=256M`, no opcache tuning, no object cache,
**When** I run `docker compose up --build` from the project root,
**Then** a local WordPress instance with the plugin mounted is reachable on `localhost`.

**Given** the running container,
**When** I `docker exec` and inspect `php -i`,
**Then** the resource limits match the Reference env: 1 CPU equivalent, `WP_MEMORY_LIMIT=256M`, opcache untuned, no Redis/Memcached object cache configured.

**Given** the Docker fixture,
**When** I run a smoke test that activates the plugin and hits the homepage,
**Then** the response succeeds with no PHP notices/warnings/errors in the container log.

### Story 1.3: Configure WPCS, PHPUnit, and CI workflow

As a developer,
I want PHP_CodeSniffer, PHPUnit, and GitHub Actions wired up to block pushes on failure,
So that WP.org review hygiene (NFR-WPORG-1, -2) and refactor safety are enforced from day one (AR-3, AR-4, AR-15).

**Acceptance Criteria:**

**Given** `phpcs.xml.dist` configured with the WordPress Coding Standards ruleset and `src/` exempted from the `WordPress.Files.FileName` rule,
**When** I run `composer run phpcs`,
**Then** the linter passes on the scaffolded plugin.

**Given** `phpunit.xml.dist` and the WP test framework bootstrap from Story 1.1,
**When** I run `composer run phpunit`,
**Then** the test suite executes (even if it has only a smoke test) and returns exit code 0.

**Given** `.github/workflows/ci.yml`,
**When** a push to any branch occurs,
**Then** the workflow runs `composer install --no-dev`, `composer install`, `composer run phpcs`, `composer run phpunit`, and blocks the push if any step fails.

**Given** a custom PHPCS sniff `SemanticPosts.PostMeta.SingleWriter`,
**When** any code outside `src/Embeddings/Vector.php`, `src/Indexing/Crawler.php`, or `src/Indexing/HashDiffDetector.php` calls `update_post_meta` / `add_post_meta` / `delete_post_meta` with a `_sp_*` key,
**Then** phpcs fails (enforces AR-10 single-writer invariant).

**Given** a custom static check on AJAX handlers,
**When** any function registered via `wp_ajax_*` is scanned,
**Then** it must call both `check_ajax_referer` AND `current_user_can` within its first 5 statements, or phpcs fails (enforces AR-12 boundary discipline).

**Given** a regex check across `assets/js/*.js`,
**When** any file contains `jQuery` token or `\$\(` jQuery shorthand,
**Then** the CI workflow fails (enforces AR-14 no-jQuery rule).

### Story 1.4: Implement Vector class for cosine compute primitive

As a developer,
I want a `Vector` utility class encapsulating the encode/decode/dot operations,
So that all downstream subsystems (Crawler, EmbedJob, SourceResolver) share a single, tested codec (AR-10 single-writer invariant for `_sp_embedding`).

**Acceptance Criteria:**

**Given** a 1536-dim `float[]` of unit-vector values,
**When** I call `Vector::encode($floats)`,
**Then** the return is a base64 string equivalent to `base64_encode(pack('f*', ...$floats))`.

**Given** a base64-encoded string produced by `Vector::encode`,
**When** I call `Vector::decode($b64)`,
**Then** the return is a `SplFixedArray<float>` whose elements match the original within `1e-6` per-element tolerance.

**Given** two unit-vector `SplFixedArray<float>` of equal dimension,
**When** I call `Vector::dot($a, $b)`,
**Then** the return is the dot product computed via explicit `for` loop, matching the mathematical cosine of unit vectors within `1e-6`.

**Given** `Vector::dot` invoked with arrays of unequal length,
**When** dimensions mismatch,
**Then** a `\LengthException` is raised.

### Story 1.5: Implement Logging static class

As a developer,
I want a single `\SemanticPosts\Logging` class formatting all log lines uniformly,
So that NFR-IDX-6 (<10 lines/day in `debug.log`) is enforceable and log archaeology is easy (AR-8).

**Acceptance Criteria:**

**Given** `Logging::warn("dirty queue stuck", ['post_id' => 42, 'subsystem' => 'DirtyQueue'])`,
**When** `WP_DEBUG_LOG=true`,
**Then** the line `[SemanticPosts][WARN] dirty queue stuck {"post_id":42,"subsystem":"DirtyQueue"}` appears in `debug.log`.

**Given** `Logging::info(...)` called when `SEMANTIC_POSTS_VERBOSE` is undefined or falsy,
**When** the call executes,
**Then** nothing is written.

**Given** `Logging::error(...)` called,
**When** the call executes regardless of `SEMANTIC_POSTS_VERBOSE`,
**Then** the line is written with `[ERROR]` level prefix.

### Story 1.6: Implement Bootstrap and centralized hook registration

As a developer,
I want `Bootstrap::registerHooks()` to be the only place `add_action`/`add_filter` is called,
So that hook archaeology and ordering issues are debuggable from one location (AR-11).

**Acceptance Criteria:**

**Given** the main plugin file calling `(new \SemanticPosts\Bootstrap())->register()`,
**When** WordPress reaches the `plugins_loaded` action,
**Then** `Bootstrap::registerHooks()` is called exactly once and registers all plugin hooks via `add_action`/`add_filter`.

**Given** a subsystem class (e.g. a stub `ContentFilter`),
**When** it exposes a public method `inject($content)`,
**Then** `Bootstrap` wires it via `add_filter('the_content', [$contentFilter, 'inject'])` rather than the subsystem registering itself.

**Given** a code-review search across `src/`,
**When** grepping for `add_action(` or `add_filter(`,
**Then** the only matches are inside `src/Bootstrap.php`.

### Story 1.7: Implement minimal Settings page (display-related fields)

As a site owner,
I want a Settings page where I can choose which post types get related-posts widgets and how they display,
So that I can configure the plugin's basic behavior before any indexing is wired up (FR-9 partial, NFR-WPORG-1).

**Acceptance Criteria:**

**Given** the plugin active and the user has `manage_options`,
**When** the user navigates to `Settings → SemanticPosts`,
**Then** the page renders with: post-types multi-select (default `post`, advisory note for commerce CPTs), display-mode radio (Auto-inject / Shortcode only / Off), and a Save Changes button.

**Given** the user changes the post-types selection,
**When** they submit the form,
**Then** `wp_verify_nonce` succeeds, the input is sanitized via `sanitize_text_field` per field, and `SettingsRepository` writes the single autoloaded option `semantic_posts_settings` whose payload is <1KB (NFR-HOST-5).

**Given** the option `semantic_posts_settings` is missing or malformed,
**When** any code reads via `SettingsRepository::get()`,
**Then** documented defaults are returned (post types = `['post']`, display mode = `auto`).

### Story 1.8: Implement SourceResolver with category-fallback

As a site owner,
I want the plugin to render the related-posts widget on every Recommendable Post even when no embeddings exist yet,
So that readers never see an empty widget and the host-compatibility story holds before indexing is enabled (FR-6 fallback, CONTEXT.md §Recommendation Source).

**Acceptance Criteria:**

**Given** a post `P` of an indexable post type with at least one category,
**When** `SourceResolver::resolve(P, $count=5)` is called and no `_sp_related` exists for `P`,
**Then** the method runs one `WP_Query` with `category__in` of P's primary category, `orderby=date`, `order=DESC`, `posts_per_page=5`, `post__not_in=[P->ID]`, and returns the IDs plus `data_source='category-fallback'`.

**Given** a post `P` of an indexable post type that has no category and no `_sp_related`,
**When** `SourceResolver::resolve(P, 5)` is called,
**Then** the method returns an empty array with `data_source='none'`.

**Given** any call to `SourceResolver::resolve`,
**When** measured via `$wpdb->num_queries` before and after,
**Then** the resolver adds at most 2 queries (one optional `get_post_meta` for `_sp_related`, one `WP_Query`).

### Story 1.9: Implement Rendering Contract template + CSS

As a site owner,
I want the related-posts widget to render with the exact HTML structure, CSS classes, and `data-sp-source` attributes defined in ADR-0007,
So that themes can override or style the widget against a stable contract (FR-8, ADR-0007).

**Acceptance Criteria:**

**Given** a Recommendation List of 5 IDs with `data_source='category-fallback'`,
**When** `\SemanticPosts\Display\Template::render($list)` is called,
**Then** the output matches ADR-0007's HTML exactly: `<section class="semantic-posts" data-sp-source="category-fallback">` wrapping an `<article class="semantic-posts-featured">` and a `<ul class="semantic-posts-grid">` with 4 `<li>`s.

**Given** the featured slot post has a featured image,
**When** the template renders,
**Then** the `<img>` uses `get_the_post_thumbnail($id, 'large')`; grid items use `'medium'`.

**Given** the featured slot post has no featured image,
**When** the template renders,
**Then** the `<img>` is omitted and the card collapses to title only (no placeholder).

**Given** identical post IDs and settings,
**When** `Template::render` is called multiple times,
**Then** the output HTML is byte-for-byte identical (NFR-PERF-5 page-cache compat).

**Given** a theme places its own template at `{theme}/semantic-posts/related-posts.php`,
**When** the template renders,
**Then** `locate_template` finds and uses the theme override; default falls back only if absent.

**Given** all rendered text strings,
**When** any user-facing string appears in output,
**Then** it is wrapped in `__()` / `esc_html__()` with text domain `semantic-posts`.

**Given** the full ADR-0007 filter surface,
**When** the template renders,
**Then** the following filters fire at the documented points and their return values are honored: `semantic_posts_heading_text` (default `__('You might also like', 'semantic-posts')`), `semantic_posts_excerpt_length` (default 160), `semantic_posts_item_classes` (default `[]`), `semantic_posts_render_html` (default identity — last-chance whole-HTML hook), `semantic_posts_template_path` (default `[]` — search list before plugin fallback), `semantic_posts_thumbnail_size` (default `['featured' => 'large', 'grid' => 'medium']`), `semantic_posts_min_score` (default 0.3, only consulted when quality-bounded mode is enabled).

**Given** the full ADR-0007 action surface,
**When** the template renders,
**Then** `do_action('semantic_posts_before_render', $list, $sourcePost)` fires before any opening tag and `do_action('semantic_posts_after_render', $list, $sourcePost)` fires after the closing `</section>`.

### Story 1.10: Implement Auto-injection ContentFilter and Shortcode

As a reader,
I want the related-posts widget to appear at the end of a single-post page (or where the site owner places the shortcode),
So that I have a clear next-article surface (FR-6, FR-7, UJ-3).

**Acceptance Criteria:**

**Given** a single-post view (`is_single()` returns true) for an indexable post type and display mode = `auto`,
**When** the `the_content` filter runs,
**Then** `ContentFilter::inject($content)` appends `Template::render(...)` output to `$content`.

**Given** the post body contains `[semantic_posts]`,
**When** `the_content` filter runs,
**Then** `ContentFilter::inject` returns `$content` unmodified and the shortcode handler renders at the shortcode position (deduplication).

**Given** a page view, archive, or homepage (`is_single()` false),
**When** `the_content` filter runs,
**Then** no related-posts widget is injected.

**Given** a post of a non-indexable post type (e.g. `attachment`),
**When** the user views it,
**Then** no widget is injected.

**Given** the shortcode `[semantic_posts count="7"]`,
**When** rendered,
**Then** the template returns 7 items if available; if the `count` is outside 3–10 it clamps to the valid range.

**Given** a post containing `[semantic_posts]` twice,
**When** rendered,
**Then** only the first invocation produces output; subsequent invocations are no-ops.

### Story 1.11: Implement clean uninstall

As a site owner,
I want uninstalling the plugin to remove every trace from my database,
So that hosts and operators trust the plugin's lifecycle hygiene (FR-12, AR-12).

**Acceptance Criteria:**

**Given** an installed plugin with `_sp_*` postmeta on multiple posts and the settings option present,
**When** the plugin is uninstalled via the WP admin (which executes `includes/uninstall.php`),
**Then** every postmeta row whose key matches `_sp_%` is deleted via batched `delete_post_meta` calls (or equivalent `$wpdb` batched DELETE), the `semantic_posts_settings` option is deleted, and the `_sp_state` option is deleted.

**Given** the plugin deactivated (not uninstalled),
**When** the user reactivates,
**Then** all previous postmeta and settings persist and the plugin resumes from its prior state.

**Given** `uninstall.php` execution on a site with no `_sp_*` data,
**When** the script runs,
**Then** it completes without errors or notices.

### Story 1.12: Implement backup-exclusion filter

As a site owner using a backup plugin that respects exclude filters,
I want the plugin to advertise which `_sp_*` postmeta keys are safe to skip from backup,
So that backup size stays bounded and restores trigger graceful re-indexing per ADR-0003 (NFR-HOST-4).

**Acceptance Criteria:**

**Given** the filter `semantic_posts_exclude_from_backup`,
**When** any consumer (backup plugin or backup-tool integration) applies the filter on the default empty array,
**Then** the return value is `['_sp_embedding', '_sp_inbound']` — the two largest derived keys safe to skip while leaving `_sp_related`, `_sp_text_hash`, `_sp_dirty` in backup so a partial restore degrades to category-fallback gracefully rather than full reindex.

**Given** the readme.txt FAQ section (Story 4.1),
**When** Story 4.1 is complete,
**Then** an FAQ entry describes the filter, the rationale (which keys are derivable in <30 min vs which are needed for graceful restore), and points backup-plugin authors at the filter name.

**Given** a post-restore state where ≥5% of Recommendable Posts lack `_sp_embedding`,
**When** the observability panel renders (Story 3.3),
**Then** the graceful-restore banner surfaces (cross-references Story 3.3).

**Given** total postmeta size at 5k posts after the filter is applied by a participating backup plugin,
**When** measured,
**Then** the backup excludes ~36 MB of `_sp_embedding` and `_sp_inbound` data, leaving ~3 MB of `_sp_related` + `_sp_text_hash` in backup (NFR-HOST-4).

## Epic 2: Semantic Indexing Pipeline

OpenAI integration produces embeddings; the crawler builds the Similarity Graph using the phased cold-start design (ADR-0008); related-posts widgets transition from category-fallback to semantic.

### Story 2.1: Implement ApiKeyStorage encryption layer

As a site owner,
I want my OpenAI API key encrypted at rest in WordPress options,
So that another plugin reading `wp_options` cannot exfiltrate it in plaintext (AR-5, NFR-WPORG-1).

**Acceptance Criteria:**

**Given** a plaintext API key `sk-...`,
**When** `\SemanticPosts\Security\ApiKeyStorage::store($key)` is called,
**Then** the stored option value is `base64({iv}:{ciphertext})` where ciphertext is AES-256-CBC encrypted with the key `hash_hmac('sha256', 'sp_api_key', AUTH_SALT, true)` and `iv` is a fresh random 16-byte IV.

**Given** an encrypted stored value,
**When** `ApiKeyStorage::retrieve()` is called,
**Then** the original plaintext key is returned.

**Given** the `AUTH_SALT` constant changes (e.g., site migration),
**When** retrieval is attempted,
**Then** `ApiKeyStorage::retrieve()` returns `null` and a warning is logged via `Logging::warn`.

**Given** unit tests in `tests/Security/ApiKeyStorageTest.php`,
**When** the test suite runs,
**Then** roundtrip + AUTH_SALT-derived-key behavior is verified.

### Story 2.2: Implement Provider interface + OpenAIProvider

As a developer,
I want the OpenAI HTTP integration encapsulated behind a `Provider` interface with classified exceptions,
So that retry routing lives in the job layer and future providers can be added without refactor (AR-9, FR-3 foundation).

**Acceptance Criteria:**

**Given** the interface `\SemanticPosts\Embeddings\Provider` with methods `name()`, `embed(string $text): array`, `maxInputTokens(): int`, `costPerMillionTokens(): float`,
**When** I implement `OpenAIProvider`,
**Then** `embed()` posts to `https://api.openai.com/v1/embeddings` via `wp_remote_post()` and returns the 1536-element `float[]`.

**Given** a 5xx, 429, or network-timeout response,
**When** `embed()` runs,
**Then** it throws `\SemanticPosts\Embeddings\Exception\RetryableException`.

**Given** a 4xx response other than 429, a malformed JSON response, or a missing API key,
**When** `embed()` runs,
**Then** it throws `\SemanticPosts\Embeddings\Exception\FatalException`.

**Given** `OpenAIProvider::name()`,
**When** called,
**Then** it returns `'openai/text-embedding-3-small'`.

**Given** unit tests with mocked HTTP responses,
**When** the test suite runs,
**Then** each response class maps to the expected exception or success path.

### Story 2.3: Implement IndexableTextBuilder

As a developer,
I want a single class composing the Indexable Text from a post per ADR-0001,
So that text composition stays consistent across save and bulk-index paths (ADR-0001).

**Acceptance Criteria:**

**Given** a post with title `T`, manual excerpt `E`, and content `C`,
**When** `IndexableTextBuilder::build($post)` is called,
**Then** the output is `{T}\n\n{T}\n\n{T}\n\n{E}\n\n{wp_strip_all_tags(C, true)}` truncated to 6500 words.

**Given** a post whose excerpt is auto-generated (matches the default excerpt rule),
**When** `build` is called,
**Then** the excerpt block is omitted from the output.

**Given** a post whose content contains shortcodes like `[gallery]`,
**When** `build` is called,
**Then** the shortcodes appear as `[gallery]` raw tokens — `do_shortcode()` is NOT invoked.

**Given** content >6500 words,
**When** `build` is called,
**Then** truncation occurs at a word boundary and a `Logging::info` line is emitted noting the truncation.

### Story 2.4: Implement EmbedJob with retry queue and RateLimiter

As a developer,
I want a single job class executing an embed call against a `Provider`, classifying outcomes, and enqueuing retries with backoff,
So that `Provider` impls stay free of retry concerns and FR-3's contract holds uniformly (AR-9, FR-3, NFR-IDX-5).

**Acceptance Criteria:**

**Given** an `EmbedJob` invoked for post `P` with attempt count 1,
**When** the `Provider::embed` call succeeds,
**Then** `_sp_embedding` is written via `Vector::encode`, `_sp_text_hash` is set, `_sp_dirty` is cleared, and the Crawler is invoked.

**Given** an `EmbedJob` invoked and `Provider::embed` throws `RetryableException`,
**When** the attempt count is <3,
**Then** the job re-enqueues itself with the next attempt index and an exponential-backoff delay (`2^attempt seconds`).

**Given** an `EmbedJob` whose retry count reaches 3,
**When** it fails again,
**Then** the post is marked failed in `_sp_state` and surfaced in the observability counter.

**Given** an `EmbedJob` invoked and `Provider::embed` throws `FatalException`,
**When** the attempt count is 1,
**Then** the post is marked failed immediately (no retries).

**Given** `RateLimiter` configured at 1 req/sec,
**When** multiple `EmbedJob` invocations run in the same cron tick,
**Then** a microsleep enforces the 1-second gap between consecutive `Provider::embed` calls.

### Story 2.5: Implement HashDiffDetector + DirtyQueue + save_post wiring

As a site owner,
I want post edits to trigger re-embedding only when content actually changed,
So that autosaves and category-only edits do not waste API spend (FR-1, ADR-0002).

**Acceptance Criteria:**

**Given** a post being saved via `save_post`,
**When** `HashDiffDetector::detect($post)` runs,
**Then** `md5(IndexableTextBuilder::build($post))` is compared to `_sp_text_hash`; if equal it returns `false`; if different it writes the new hash and sets `_sp_dirty=1`.

**Given** an autosave event,
**When** `save_post` fires,
**Then** `HashDiffDetector::detect` is short-circuited via `wp_is_post_autosave()` and returns `false`.

**Given** a post transitioning to `publish` for the first time (no prior `_sp_embedding`),
**When** `transition_post_status` fires with `$new_status='publish'` and `$old_status` in {`draft`,`future`,`pending`,`auto-draft`},
**Then** an immediate `EmbedJob` is scheduled (bypasses the dirty queue) so related-posts data is available without waiting for the next cron tick.

**Given** a post moved to trash, password-protected, or transitioned away from `publish`,
**When** the relevant hook fires,
**Then** `_sp_embedding`, `_sp_related`, `_sp_inbound`, `_sp_text_hash`, `_sp_dirty` are deleted for that post and any other post's `_sp_related` that pointed to it is invalidated for re-computation.

**Given** `DirtyQueue::pending()` reads from postmeta,
**When** called,
**Then** it returns up to 50 post IDs with `_sp_dirty=1`, ordered by `post_modified DESC`.

### Story 2.6: Implement TickProcessor with work-stealing drain and `_sp_state`

As a developer,
I want a single registered cron event running a work-stealing drain across cold-start, dirty queue, and verification pass,
So that orchestration lives in one place and cron sprawl is avoided (AR-6, AR-7, NFR-IDX-2/3/4).

**Acceptance Criteria:**

**Given** plugin activation,
**When** `Activator::activate` runs,
**Then** a single hourly cron event `semantic_posts_cron_tick` is scheduled, callback `\SemanticPosts\Indexing\TickProcessor::run`.

**Given** a cron tick firing,
**When** `TickProcessor::run()` executes,
**Then** it (1) advances cold-start by one batch if cold-start is active, (2) drains up to 50 dirty-queue items if cold-start idle, (3) runs verification pass if `_sp_state.verification.next_due` ≤ now.

**Given** `_sp_state` stored as the option key `_sp_state`,
**When** the option is created,
**Then** it is NOT autoloaded (`add_option('_sp_state', [...], '', 'no')`).

**Given** PHP memory usage reaches 80% of `WP_MEMORY_LIMIT` during a tick,
**When** measured between sub-units of work,
**Then** the tick halts gracefully, persists `_sp_state`, and exits — the next tick resumes from the persisted state.

**Given** a deactivation hook firing,
**When** `Deactivator::deactivate` runs,
**Then** `wp_clear_scheduled_hook('semantic_posts_cron_tick')` is called and `_sp_state` is preserved.

**Given** a steady-state simulation across 24 simulated cron ticks (5 dirty posts/hour + one verification pass occurring within the window),
**When** lines written to `debug.log` are counted at the end of 24h,
**Then** the count is fewer than 10 (NFR-IDX-6). The test simulates this by stubbing OpenAI calls to succeed instantly and running `TickProcessor::run` 24 times in sequence.

### Story 2.7: Implement Crawler warm path (update mode)

As a developer,
I want the crawler to update a post's `_sp_related` incrementally when its embedding changes,
So that steady-state indexing cost stays O(K²) regardless of corpus size (FR-4, ADR-0004).

**Acceptance Criteria:**

**Given** post `X` whose embedding just changed and an existing graph with `_sp_related(X)` and `_sp_inbound(X)`,
**When** `Crawler::update($postId)` is called,
**Then** a candidate set is built from `_sp_related(X)` ∪ neighbors-of-those ∪ `_sp_inbound(X)` ∪ a random sample of 10 Indexable Posts.

**Given** the candidate set,
**When** cosine is computed via `Vector::dot` for each candidate,
**Then** the top-K candidates by the active Ranking Mode score become X's new `_sp_related`, replacing the prior value atomically.

**Given** the new `_sp_related(X)`,
**When** the crawler propagates,
**Then** for every neighbor `N` in {X's old outbound ∪ new outbound ∪ old inbound}, `cosine(N, X)` is recomputed and `_sp_related(N)` and `_sp_inbound(N)` are updated if X enters or leaves N's top-K.

**Given** a multilingual site (Polylang or WPML detected via `function_exists`),
**When** the candidate set is built,
**Then** all candidates are filtered to the same language as X via `pll_get_post_language` / `icl_object_id`. Filter `semantic_posts_disable_language_filter` overrides this.

**Given** a unit test on a 100-post fixture,
**When** `Crawler::update` is invoked,
**Then** total cosine ops measured is ≤200 regardless of corpus size.

### Story 2.8: Implement Crawler insert mode + ColdStart phase manager

As a site owner,
I want cold-start indexing to be memory-bounded and use graph traversal once past the bootstrap phase,
So that 5k+ post corpora can be indexed without exceeding `WP_MEMORY_LIMIT=256M` (FR-4b, ADR-0008).

**Acceptance Criteria:**

**Given** cold-start active with `_sp_state.cold_start.phase = 'bootstrap'` and corpus indexed count `<200`,
**When** `ColdStart::processBatch()` runs for 50 outer posts,
**Then** each outer post is brute-force compared pairwise against ALL already-indexed posts via `Crawler::insert` in bootstrap mode.

**Given** corpus indexed count reaches 200,
**When** the next batch begins,
**Then** `_sp_state.cold_start.phase` transitions to `'graph_knn'` and subsequent inserts use graph-walk mode.

**Given** `Crawler::insert($postId)` in graph-walk mode,
**When** called,
**Then** L=5 random already-indexed posts are picked as entry points; a greedy best-first walk visits up to B_v=300 nodes via `_sp_related` ∪ `_sp_inbound` expansion; the heap of top-K cosines becomes X's `_sp_related`.

**Given** a walk where the highest-cosine unvisited candidate is below the heap minimum,
**When** evaluated,
**Then** the walk terminates early (before exhausting B_v=300).

**Given** memory bookkeeping during a graph-walk tick,
**When** the visit budget is fully used,
**Then** measured peak memory for the inner loop is ≤10 MB on the Reference env Docker fixture.

**Given** a post being walked,
**When** postmeta reads are batched,
**Then** `update_meta_cache('post', $candidate_ids)` is called once per batch (≤4 batch queries per insert).

### Story 2.9: Implement Ranking Mode strategies

As a site owner,
I want to choose between "Most relevant", "Fresh-first", and "Diverse mix" ranking,
So that my site's content type drives the recommendation order (FR-4a, ADR-0006).

**Acceptance Criteria:**

**Given** the `\SemanticPosts\Ranking\Mode` interface with method `rank(array $candidates, float[] $sourceEmbedding, int $k): array`,
**When** `MostRelevantMode::rank` is called,
**Then** the return is candidates sorted by descending cosine, top-K.

**Given** `FreshFirstMode::rank`,
**When** called with `decay_days` default 180,
**Then** each candidate's effective score is `cosine × exp(-age_days / 180)`; top-K by effective score; filter `semantic_posts_recency_decay` overrides the constant.

**Given** `DiverseMixMode::rank` with `λ` default 0.7,
**When** called,
**Then** item 1 is highest cosine; items 2–K iteratively maximize `λ × cosine(post, candidate) − (1−λ) × max_cosine(candidate, already_picked)`; filter `semantic_posts_mmr_lambda` overrides the constant.

**Given** any mode,
**When** the active selection comes from `SettingsRepository::getRankingMode()` and is invoked from `Crawler::update` / `::insert`,
**Then** the featured #1 in the final `_sp_related` is always the highest-cosine candidate regardless of mode (NFR-QUAL-4).

### Story 2.10: Upgrade SourceResolver to semantic with multilingual filter

As a reader,
I want related-posts widgets to show semantically related posts in my page's language,
So that the experience is coherent on multilingual sites (FR-6 semantic path, multilingual defensive filter).

**Acceptance Criteria:**

**Given** a post `P` with a non-empty `_sp_related`,
**When** `SourceResolver::resolve(P, $count)` is called,
**Then** the IDs come from `_sp_related` (parsed via JSON decode) and `data_source` is `'semantic'`.

**Given** a multilingual site (Polylang or WPML detected),
**When** resolving for a post in language `pt`,
**Then** any candidate IDs in `_sp_related` not in the same language as `P` are filtered out before returning.

**Given** the language filter removes enough candidates that fewer than `min_items` remain,
**When** quality-bounded mode is off,
**Then** the resolver pads from category-fallback up to `$count` items, mixing `data_source='semantic'` items and `data_source='category-fallback'` items, with each item carrying `data_source` per origin.

**Given** quality-bounded mode is on with `score_threshold=0.3`,
**When** resolving,
**Then** any semantic items with cosine score below the threshold are dropped; the list size shrinks rather than padding.

**Given** the filter `semantic_posts_disable_language_filter` returning `true`,
**When** resolving on a multilingual site,
**Then** the language filter is skipped.

### Story 2.11: Expand Settings page to full FR-9 surface

As a site owner,
I want the Settings page to expose API key (encrypted), embedding model, related-post count, Ranking Mode, Quality-bounded toggle, and cron frequency,
So that I can fully configure semantic indexing behavior (FR-9 full, NFR-ON-4).

**Acceptance Criteria:**

**Given** the Settings page from Story 1.7,
**When** Story 2.11 is complete,
**Then** the page additionally includes: API key field (masked), Embedding model dropdown (default `openai/text-embedding-3-small`), Number of related posts (3–10, default 5), Ranking Mode radio, Quality-bounded checkbox (revealing `min_items` and `score_threshold` when checked), Cron frequency select.

**Given** the API key field on save,
**When** the user submits,
**Then** the key is validated by a single test call to `Provider::embed("test")`; on success it is stored via `ApiKeyStorage::store`; on failure an inline error appears.

**Given** the user changes the Embedding model dropdown to a different value,
**When** they submit,
**Then** a modal confirms: *"This will regenerate embeddings for all N posts, costing approximately $X via OpenAI. Continue?"*; on confirm, all `_sp_*` postmeta is wiped and cold-start is triggered (ADR-0005).

**Given** the Cost Preview line,
**When** the user changes related-post count or model dropdown,
**Then** the displayed estimate updates live (via vanilla JS fetching `wp-admin/admin-ajax.php?action=semantic_posts_cost_preview` with nonce).

**Given** the corpus has <50 Indexable Posts,
**When** the Settings page renders,
**Then** a one-time non-blocking notice appears: *"SemanticPosts works best with 50+ posts. You currently have N; recommendations may be sparse until your library grows."* — dismissible.

**Given** the saved options payload,
**When** measured,
**Then** total option size is <1KB (NFR-HOST-5).

### Story 2.12: Implement Bulk Index admin UI with cost preview + progress

As a site owner,
I want a "Start indexing" action with an upfront cost estimate and a visible progress bar,
So that I know what I'm spending before I commit (FR-2, NFR-ON-4, UJ-1).

**Acceptance Criteria:**

**Given** the Settings page or first-run wizard,
**When** the user clicks "Start indexing",
**Then** a confirmation modal shows: *"Indexing your N posts will cost approximately $X via OpenAI. Continue?"*; on confirm, `ColdStart::start()` is called and `_sp_state.cold_start.phase` transitions from `'idle'` to `'bootstrap'`.

**Given** cold-start active,
**When** the observability panel or wizard polls progress,
**Then** the current `(last_processed_id, total_pending, phase)` is returned and rendered as a progress bar.

**Given** the user navigates away mid-indexing,
**When** they return,
**Then** the progress UI reflects current state from `_sp_state`.

**Given** "Start indexing" clicked with no API key configured,
**When** the click handler runs,
**Then** a notice prompts the user to set the API key first; cold-start does not initiate.

## Epic 3: Operational Surface (Verify, Observe, CLI)

Owner has full audit visibility (UJ-4) and host engineers can verify the host-compatibility story in 30 seconds (UJ-5). Manual maintenance actions are exposed. WP-CLI mirrors admin operations.

### Story 3.1: Implement VerificationPass

As a site owner,
I want a weekly check that the crawler's approximations haven't drifted from brute-force optimal,
So that I get an early signal if my graph quality has degraded (FR-5, EV-05).

**Acceptance Criteria:**

**Given** `VerificationPass::run()` invoked by `TickProcessor` when `_sp_state.verification.next_due` ≤ now,
**When** executed,
**Then** M=20 random Indexable Posts are sampled and for each, brute-force top-5 is computed against the full corpus and compared to the graph's `_sp_related` via Spearman footrule.

**Given** the M=20 footrule values,
**When** the mean (MRD) is computed,
**Then** `_sp_state.verification.last_mrd` is written and `_sp_state.verification.next_due` is set to now + 7 days.

**Given** MRD ≥ 1.5 (the threshold from EV-05),
**When** the verification pass completes,
**Then** an admin notice with action "Reindex all" is enqueued via `admin_notices`.

**Given** the filter `semantic_posts_verification_threshold`,
**When** it returns a value other than 1.5,
**Then** the new threshold is used.

**Given** `VerificationPass` invocation on a corpus smaller than M=20,
**When** executed,
**Then** all available Indexable Posts are used and MRD is computed normally.

### Story 3.2: Implement observability Metrics aggregator

As a site owner,
I want 24-hour counters of plugin activity persisted across cron ticks,
So that the observability panel reflects accurate recent behavior (FR-10 data source).

**Acceptance Criteria:**

**Given** `Metrics::record('embedding_call', ['tokens' => N, 'cost' => $cost])`,
**When** called from `EmbedJob`,
**Then** the daily counter (keyed by `Y-m-d`) is incremented in `_sp_state.metrics` and old day-buckets older than 25h are pruned.

**Given** `Metrics::recordCronTick(['posts_processed' => N, 'outcome' => 'ok'|'partial'|'error', 'duration_ms' => D, 'peak_memory_mb' => M])`,
**When** called at the end of each `TickProcessor::run`,
**Then** the latest tick summary is appended to a ring buffer of size 24 in `_sp_state.metrics.recent_ticks`.

**Given** `Metrics::recordRenderQueryCount($count)`,
**When** called from the `SourceResolver`,
**Then** a 24h moving counter is updated; the observability panel exposes the average and max.

**Given** `Metrics::summary24h()`,
**When** called,
**Then** it returns: embedding call count + cost, queue size, failed-post count, last tick timestamp + outcome, average + max render-path queries per pageview, peak memory (MB).

### Story 3.3: Implement Observability Panel UI

As a site owner or host engineer,
I want a single admin screen surfacing 24-hour plugin activity,
So that I can audit health in 30 seconds without contacting support (FR-10, UJ-4, UJ-5).

**Acceptance Criteria:**

**Given** the user navigates to the SemanticPosts admin page,
**When** the page renders,
**Then** an `ObservabilityPanel` section displays: embedding API calls (24h) + cost; posts in queue / failed (with "Retry failed" button); last cron tick timestamp + outcome; last verification pass timestamp + MRD; render-path queries added (24h, target ≤2); peak indexing memory (24h MB); recent activity log tail (~50 events).

**Given** the panel renders on a 5,000-post indexed site,
**When** the page-load time is measured,
**Then** the panel renders in <200 ms server-time on the Reference env.

**Given** ≥5% of Recommendable Posts lack `_sp_embedding`,
**When** the panel renders,
**Then** a graceful-restore banner reads *"Reindexing in progress. X / Y posts indexed."* until the ratio drops below 5%.

**Given** the user has not configured an API key,
**When** the panel renders,
**Then** a banner reads *"Configure your API key to enable semantic recommendations."*

**Given** all panel data is rendered via PHP server-side,
**When** the page loads,
**Then** the rendered HTML contains zero JS errors in the browser console.

### Story 3.4: Implement admin maintenance actions

As a site owner,
I want one-click maintenance actions (Reindex, Retry failed, Verify now, Run indexing now),
So that I can fix issues without using CLI or contacting support (FR-5, FR-10 actions, AR-14).

**Acceptance Criteria:**

**Given** the observability panel,
**When** the "Run indexing now" button is clicked,
**Then** a fetch request to `admin-ajax.php?action=semantic_posts_run_indexing_now` with nonce runs one `TickProcessor::run` synchronously and returns the resulting metrics; the panel refreshes to show the new tick summary.

**Given** the "Retry failed" button,
**When** clicked,
**Then** posts marked failed in `_sp_state` are re-enqueued for embedding; the failed counter resets.

**Given** the "Reindex all" button,
**When** clicked,
**Then** a confirmation modal warns about cost (*"This will regenerate embeddings for N posts at approximately $X..."*); on confirm, all `_sp_*` postmeta is wiped and cold-start is triggered.

**Given** the "Run verification now" button,
**When** clicked,
**Then** `VerificationPass::run()` executes and returns the resulting MRD value; the panel updates `_sp_state.verification.last_mrd`.

**Given** any of the 4 actions,
**When** the click handler runs,
**Then** `check_ajax_referer` succeeds, `current_user_can('manage_options')` passes, the action proceeds; otherwise `wp_send_json_error([...], 403)`.

### Story 3.5: Implement WP-CLI commands

As an operator running a WP-Cron-disabled host,
I want six WP-CLI commands mirroring admin operations,
So that I can manage indexing from the shell (FR-11, NFR-IDX-4).

**Acceptance Criteria:**

**Given** the WP-CLI integration registered via `WP_CLI::add_command('semantic-posts', \SemanticPosts\CLI\Commands::class)`,
**When** the user runs `wp semantic-posts index`,
**Then** cold-start is initiated with the same batching, rate-limiting, and resumability as the admin action.

**Given** `wp semantic-posts reindex`,
**When** invoked,
**Then** all `_sp_*` postmeta is wiped and a fresh cold-start runs.

**Given** `wp semantic-posts process-dirty`,
**When** invoked,
**Then** one drain of the dirty queue is performed manually (FR-1 hourly tick on demand).

**Given** `wp semantic-posts verify`,
**When** invoked,
**Then** `VerificationPass::run` executes and prints `MRD = X` to stdout.

**Given** `wp semantic-posts retry-failed`,
**When** invoked,
**Then** failed flags are cleared and posts are re-enqueued.

**Given** `wp semantic-posts status --format=json`,
**When** invoked,
**Then** the same payload as `Metrics::summary24h()` is printed as JSON to stdout, exit code 0.

**Given** any command failing,
**When** the failure occurs,
**Then** the command prints an error and exits non-zero.

### Story 3.6: Surface EV registry values in the observability panel

As a site owner and as the product team running Day-30 / Day-90 retros,
I want the current effective values of the 15 EV-registry tuning constants visible in the admin observability panel,
So that retros can read live values without grepping the codebase and so that post-launch tuning decisions are grounded in observed values rather than guesses (AR-13).

**Acceptance Criteria:**

**Given** the observability panel from Story 3.3,
**When** the page renders,
**Then** an "Algorithm Constants" section lists all 15 EV-IDs (EV-01 through EV-15) in a read-only table with columns: `EV-ID`, `Name`, `Current value`, `Source` (`default` / `filtered` / `setting`), `Revisit trigger summary`.

**Given** a constant exposed via a filter (e.g. `semantic_posts_cold_start_visit_budget` for EV-02),
**When** the panel reads the effective value,
**Then** it applies the filter via `apply_filters(...)` on the documented default and shows the post-filter value with `Source = 'filtered'` if a third party overrode it, `Source = 'default'` otherwise.

**Given** a constant tied to a setting (e.g. EV-13 quality-bounded score threshold = `score_threshold` in `semantic_posts_settings`),
**When** the panel reads the effective value,
**Then** it reads from `SettingsRepository::get()` and shows `Source = 'setting'`.

**Given** the table rendering,
**When** measured,
**Then** rendering the EV section adds at most 50ms to the observability panel render time (no per-row API call or expensive lookups).

**Given** a code review,
**When** the EV ID list is grepped against the architecture.md "Decisions Pending Empirical Validation" registry,
**Then** all 15 IDs match — the panel and the architecture document share a single source of truth for EV-ID metadata. Updating the registry in architecture.md must be reflected in the panel via a single config array (e.g. `src/Observability/EVRegistry.php` exporting the same content as the markdown table).

## Epic 4: Launch Readiness (WP.org Submission + Benchmark)

Plugin passes `wp plugin check`; `readme.txt` + `.pot` finalized; CI workflow blocks pushes on phpcs + phpunit + plugin-check; nightly benchmark publishes data the marketing site consumes.

### Story 4.1: Finalize readme.txt with screenshots, FAQ, and compatibility copy

As a WP.org browser,
I want a complete plugin listing with screenshots and a clear FAQ,
So that I can decide whether SemanticPosts fits my site in under 60 seconds (NFR-WPORG-5, NFR-WPORG-6, NFR-ON-1).

**Acceptance Criteria:**

**Given** `readme.txt`,
**When** WordPress.org's `readme.txt` validator parses it,
**Then** it identifies: Stable Tag matching the plugin version, Tested up to (current major WP version), Requires PHP `8.0`, screenshots referenced in `assets/`, full FAQ section, host-compatibility copy line ("Tested with WP Engine, Kinsta, SiteGround, Cloudways, Pressable") wired to runtime per NFR-WPORG-6.

**Given** the screenshot files in `assets/`,
**When** WP.org renders the listing,
**Then** 4 screenshots are visible: settings page, observability panel, related-posts widget (frontend), and bulk-index confirmation modal.

**Given** the FAQ section,
**When** reviewers read it,
**Then** it answers: how the plugin avoids host audits (architecture paragraph + diagram link), how much it costs (real dollar examples for 200/1k/5k posts), what data is sent to OpenAI (full transparency per PRD §10.2), how multilingual is handled, what happens on uninstall.

### Story 4.2: Regenerate the .pot file covering final string set

As a translator,
I want a `.pot` file containing every user-facing string with the `semantic-posts` text domain,
So that translation packs can be produced for WP.org (NFR-WPORG-4).

**Acceptance Criteria:**

**Given** the plugin source tree,
**When** I run `wp i18n make-pot . languages/semantic-posts.pot`,
**Then** the file is generated and contains every translatable string from `src/`, `templates/`, and `assets/js/` (translatable strings in JS via `wp_set_script_translations`).

**Given** a code review,
**When** any user-facing string is found in PHP without `__()`, `_e()`, `esc_html__()`, etc., with text domain `semantic-posts`,
**Then** the build fails (custom phpcs sniff or test assertion).

**Given** the resulting `.pot`,
**When** opened in Poedit or equivalent,
**Then** it lists ≥40 strings (rough lower bound for a plugin this size) without parsing errors.

### Story 4.3: Pass `wp plugin check` and remediate any findings

As a developer preparing the WP.org submission,
I want the plugin to pass `wp plugin check` cleanly,
So that the WP.org reviewer's manual pass has nothing to flag (NFR-WPORG-1, NFR-WPORG-2, NFR-WPORG-3).

**Acceptance Criteria:**

**Given** `wp plugin check semantic-posts` run against the built plugin (after `composer install --no-dev`),
**When** the check runs,
**Then** zero `ERROR` findings; any `WARNING` findings are documented in `readme.txt` FAQ with rationale (e.g., the `base64_encode` for embedding storage with explanation that it is NOT used for code execution).

**Given** the plugin activated on a fresh install,
**When** `WP_DEBUG=true` is enabled,
**Then** zero PHP notices, warnings, or errors appear in `debug.log` across the full UJ-1 flow.

**Given** the plugin's admin pages opened in a browser with the dev console,
**When** the user navigates settings + observability,
**Then** zero JS errors appear in the console.

### Story 4.4: Wire up the nightly benchmark workflow

As a marketing-site operator (and host engineer),
I want a public, reproducible benchmark output from CI that demonstrates the plugin's render-path cost,
So that the host-compatibility positioning is backed by real numbers (NFR-MKT-1, AR-16).

**Acceptance Criteria:**

**Given** `.github/workflows/benchmark.yml`,
**When** the nightly cron runs on `main`,
**Then** it boots the Reference env Docker fixture from Story 1.2, runs the seeded benchmark suite under `tests/Performance/` against a 5,000-post fixture, and outputs a JSON results file.

**Given** the JSON output,
**When** measured,
**Then** it contains: TTFB delta vs no-plugin baseline (NFR-PERF-1), queries-added-per-pageview (NFR-PERF-2), HTTP requests during render (NFR-PERF-3), memory added per request (NFR-PERF-4), cold-start wall time for 5k posts (NFR-IDX-1).

**Given** the benchmark workflow,
**When** any of the published numbers regresses beyond a configured tolerance (PERF-1 delta exceeds 5 ms; PERF-2 exceeds 2 queries; PERF-3 != 0; PERF-4 exceeds 1 MB),
**Then** the workflow fails and a GitHub Actions notification fires.

**Given** the workflow output published to a `benchmarks/` branch (or as a workflow artifact),
**When** the marketing site fetches the latest numbers,
**Then** the numbers are reachable via a stable public URL.

**Given** the render-path benchmark invocation,
**When** the `pre_http_request` WordPress hook is registered to count outbound HTTP calls during the rendered page lifecycle,
**Then** the counter equals zero at the end of every benchmark run (NFR-PERF-3 made explicit).

**Given** a benchmark variant where Redis object cache is enabled in the Docker fixture,
**When** the render-path benchmark runs,
**Then** TTFB delta and queries-added stay within the same tolerance as the no-object-cache run and zero behavioral diff is detected between the two configurations (NFR-HOST-3).

**Given** an end-to-end fresh-install simulation (wp-cli plugin activate → wp option set for API key → trigger cold-start kickoff via CLI),
**When** wall-clock from activation to "cold start running" state is measured,
**Then** it completes in under 300 seconds (5 minutes) on the Reference env (NFR-ON-1).

### Story 4.5: Marketing-site benchmark data export

As a marketing-site consumer,
I want the benchmark output exposed in a stable schema,
So that the marketing site can fetch and render it without coordination with plugin releases (NFR-MKT-1 closure).

**Acceptance Criteria:**

**Given** the JSON benchmark output from Story 4.4,
**When** consumed,
**Then** it conforms to a documented schema (`docs/benchmark-schema.md`): top-level keys `version`, `timestamp`, `commit_sha`, `environment`, `results` (object keyed by NFR ID).

**Given** schema versioning,
**When** breaking changes are made,
**Then** the `version` key is incremented and the previous schema's output remains available at a historical URL (or is captured in git history).

**Given** the marketing site contract,
**When** marketing-site work is done by a separate session/team,
**Then** they consume the published URL without needing the plugin source.

_Marketing-site templating, copywriting, the host-compatibility list (NFR-MKT-2, NFR-MKT-3), and the named SEO listicle (NFR-MKT-4) are explicit out-of-plugin-scope per architecture.md and PRD addendum §J. Story 4.5 closes only the data-export side of the plugin's NFR-MKT-1 obligation._

### Story 4.6: Pre-launch quality evaluation (NFR-QUAL-1/2/3 gate)

As the product owner,
I want a documented blind-comparison evaluation against a category-matching baseline run before WP.org submission,
So that NFR-QUAL-1/2/3 are verified as launch gates and the "actually related" positioning is backed by evidence (NFR-QUAL-1, -2, -3).

**Acceptance Criteria:**

**Given** a curated test corpus of 100 posts spanning 5 categories (corpus snapshot frozen as `tests/fixtures/quality-eval-corpus-v1.json` or equivalent reproducible source),
**When** the evaluator (product owner) runs the protocol blind to provenance with order randomized,
**Then** for each source post they answer per-pair *"Is this suggestion a reasonable next-read for a typical site visitor — yes/no?"* against SP top-5 and a category-matching baseline (Jetpack Related Posts or Same Category Posts top-5 as proxy).

**Given** evaluation results aggregated across the 100 posts,
**When** the per-post pass criterion is computed,
**Then** ≥3 of 5 SP suggestions per source post are judged reasonable AND SP's "yes rate" averaged across the corpus exceeds the baseline's "yes rate" (NFR-QUAL-1).

**Given** the same 100-post fixture,
**When** measuring cross-category rate of top-5 SP suggestions,
**Then** 20% ≤ rate ≤ 50% (NFR-QUAL-2 + counter-metric SM-C2).

**Given** a separate 50-post manual review using the "completely unrelated" cross-domain rubric (e.g., recipe blog → tax software = fail; kitchen tools → kitchen recipes = pass),
**When** the review completes,
**Then** zero failures occur (NFR-QUAL-3).

**Given** any of the three gates fail,
**When** evaluated,
**Then** WP.org submission is blocked until algorithmic constants in the EV registry (EV-01 through EV-15) are tuned and the gate re-runs pass. The eval run is documented in a markdown report committed to `docs/quality-evals/` with date, fixture version, per-post results, and final pass/fail.

**Given** the eval methodology described,
**When** documented in `docs/quality-evals/methodology.md`,
**Then** the methodology is reproducible by a second evaluator (PRD §9.4 single-evaluator caveat is acknowledged; multi-rater eval is post-v1 future work per NFR-QUAL-1 assumption tag).

### Story 4.7: Production deployment zip script

As a maintainer preparing the WP.org submission,
I want a single command that produces the production zip reproducibly,
So that the artifact is exact, excludes dev-only files, and ships exactly what WP.org reviewers expect (AR-17, NFR-WPORG-1).

**Acceptance Criteria:**

**Given** a Composer script `composer build` defined in `composer.json`,
**When** invoked from a clean checkout,
**Then** the script runs `composer install --no-dev --optimize-autoloader` followed by `zip` (or equivalent) and produces `semantic-posts-{version}.zip` containing only: `semantic-posts.php`, `readme.txt`, `LICENSE`, `vendor/` (autoload + any prod runtime deps), `src/`, `includes/`, `templates/`, `assets/`, `languages/`.

**Given** the produced zip,
**When** unzipped and inspected,
**Then** the following are NOT present: `tests/`, `docker/`, `.github/`, `composer.json`, `composer.lock`, `phpcs.xml.dist`, `phpunit.xml.dist`, `.editorconfig`, `.wp-env.json`, `.gitignore`, `docs/`, `spike/`.

**Given** the produced zip,
**When** uploaded to a fresh WordPress install and activated,
**Then** the plugin activates without errors and `wp plugin check` (Story 4.3) passes.

**Given** a GitHub Release tag pushed (e.g. `v1.0.0`),
**When** the GitHub Actions release workflow runs (`.github/workflows/release.yml`),
**Then** the workflow executes `composer build`, attaches the resulting zip to the GitHub Release as the canonical artifact, and prints the zip's sha256 in the workflow log for traceability.

**Given** the script invocation order,
**When** executed,
**Then** it is idempotent — running twice in succession produces byte-identical output (deterministic vendor dir + deterministic zip ordering via sorted file inputs).
