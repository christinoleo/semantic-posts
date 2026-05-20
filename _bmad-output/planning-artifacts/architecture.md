---
stepsCompleted: [1, 2, 3, 4, 5, 6, 7, 8]
lastStep: 8
status: 'complete'
completedAt: '2026-05-20'
inputDocuments:
  - _bmad-output/planning-artifacts/project-brief.md
  - _bmad-output/planning-artifacts/prds/prd-semanticPosts-2026-05-20/prd.md
  - _bmad-output/planning-artifacts/prds/prd-semanticPosts-2026-05-20/addendum.md
  - _bmad-output/planning-artifacts/prds/prd-semanticPosts-2026-05-20/.decision-log.md
  - docs/CONTEXT.md
  - docs/adr/0001-indexable-text-composition.md
  - docs/adr/0002-embedding-regeneration-triggers.md
  - docs/adr/0003-storage-postmeta-derived.md
  - docs/adr/0004-crawler-based-indexing.md
  - docs/adr/0005-no-per-embedding-model-versioning.md
  - docs/adr/0006-recommendation-ranking-modes.md
  - docs/adr/0007-rendering-contract.md
  - docs/adr/0008-phased-cold-start-knn.md
  - docs/spikes/0001-php-cosine-viability.md
  - spike/spike.php
workflowType: 'architecture'
project_name: 'semanticPosts'
user_name: 'Christinoleo'
date: '2026-05-20'
adrPosture: 'locked-fill-gaps-only'
adrsCreatedDuringWorkflow:
  - 0008-phased-cold-start-knn.md
---

# Architecture Decision Document

_This document builds collaboratively through step-by-step discovery. Sections are appended as we work through each architectural decision together._

## Project Context Analysis

### Requirements Overview

**Functional Requirements (12 FRs across 7 capability groups):**

- §4.1 Indexing (FR-1, FR-2, FR-3) — per-post hash-diff trigger on `save_post`; bulk cold-start
  with progress; uniform retry-with-backoff failure handling
- §4.2 Similarity Graph maintenance (FR-4, FR-4a, FR-4b, FR-5) — crawler-based incremental
  update + phased cold start + verification pass + manual reindex; three Ranking Modes
- §4.3 Display (FR-6, FR-7, FR-8) — auto-injection via `the_content`, `[semantic_posts]`
  shortcode, featured-first template per Rendering Contract (ADR-0007)
- §4.4 Settings (FR-9) — single admin page, one autoloaded option ≤1KB
- §4.5 Observability (FR-10) — single-screen 24h panel powering UJ-4 (owner) and UJ-5 (host
  engineer audit)
- §4.6 WP-CLI (FR-11) — six commands mirroring admin operations for WP-Cron-disabled hosts
- §4.7 Uninstall (FR-12) — removes all `_sp_*` postmeta and the settings option

**Non-Functional Requirements (load-bearing):**

| Group | What it forces architecturally |
|---|---|
| PERF (5) | Render path = 2 indexed queries + 0 HTTP + <1MB memory + <5ms TTFB delta + cacheable HTML |
| HOST (5) | One autoloaded option ≤1KB, binary embeddings, backup-exclusion filter, object-cache compatible without config |
| IDX (6) | All bulk ops batched + resumable + memory-bounded (halt at 80% `WP_MEMORY_LIMIT`); WP-Cron-disabled fallback via CLI + admin button |
| QUAL (4) | Featured #1 = highest cosine across all modes; 20–50% cross-category target as quality signal |
| ON (4) + Reference env | PHP 8.0+, MySQL 5.7+ (no MySQL 8 VECTOR), targets measured on 1 vCPU / 1GB / 256M PHP / no opcache / no object cache |
| WPORG (6) | Sanitize / nonce / escape / i18n / no `eval`-style code execution / `readme.txt` discipline / listing copy |
| MKT (4) | Reproducible benchmark + architecture paragraph + diagram + host-compat list above the fold |

### Scale & Complexity

- **Primary domain:** WordPress plugin — PHP backend with WP-Cron / WP-CLI workers,
  postmeta-only persistence, thin admin UI, zero-JS frontend render template.
- **Complexity level:** Medium. Bounded surface (one plugin, one primary persona, no
  multi-tenancy, no real-time, no compliance overhead). The complexity is concentrated in
  the unified crawler (warm + cold-start phased) and the indexing-vs-render path
  discipline, not in feature breadth.
- **Estimated architectural components:** ~14–16 (provider abstraction, indexable-text
  builder, hash-diff change detector, dirty queue, cron tick processor, unified crawler
  with insert + update entry strategies per ADR-0008, cold-start phase manager, verification
  scheduler, ranking strategies, source resolver, render template + filter surface,
  content-filter auto-injector + shortcode handler, settings page + cost preview,
  observability collector + panel, WP-CLI command handlers, uninstall handler).

### Technical Constraints & Dependencies

- **Platform floor:** WordPress 6.0+, PHP 8.0+, MySQL 5.7+ / MariaDB 10.3+. No PHP 8.2+
  syntax, no MySQL 8 `VECTOR` type, no pgvector. Object cache (Redis/Memcached) supported
  without manual configuration.
- **Reference environment for all hardware-dependent NFRs (PRD §9.5):** 1 vCPU, 1GB RAM,
  `WP_MEMORY_LIMIT=256M`, no opcache tuning, default WP-Cron, no object cache. Targets met
  on better hardware do not satisfy NFRs.
- **No external infrastructure (positioning constraint, PRD §5):** no Qdrant, Pinecone,
  Supabase, Typesense, pgvector, external workers, or self-hosted services. Pure-PHP cosine
  over base64-encoded `float32` postmeta. Spike-validated at 5k posts (587s brute
  compute, 130MB peak), memory-bounded for >5k via ADR-0008's phased cold start.
- **Storage budget (NFR-HOST-4):** total postmeta added at 5k posts <50MB. Spike measured
  39MB packed (within budget).
- **Autoloaded options (NFR-HOST-5):** exactly 1 option, total <1KB. All per-post state in
  postmeta.
- **Multisite:** explicitly out of v1 (PRD §13).
- **External dependencies:** OpenAI Embeddings API only (`text-embedding-3-small` v1).
  Internal Provider abstraction prepared for future providers but not user-facing.

### Cross-Cutting Concerns Identified

1. **Render-path discipline** — Two paths separated by a hard wall: render path is allowed
   exactly `get_post_meta` + `WP_Query`; nothing else. Indexing path is the only place
   external HTTP and heavy CPU are allowed, and only from WP-Cron / WP-CLI.
2. **Resumability** — Every bulk operation (cold start, manual reindex, retry-failed,
   verification pass) must survive process kill, WP-Cron disable mid-run, and PHP memory
   ceiling. Phased cold start (ADR-0008) carries phase + position in a transient.
3. **Source-resolution chain** — `semantic` → silent `category-fallback` → empty. Uniform
   across both display surfaces. Resolves to `data-sp-source` data attributes for admin
   inspection; never surfaced to readers.
4. **Cost transparency** — Every action that spends API credit (bulk index, manual reindex,
   embedding-model change) is gated behind an explicit dollar-estimate confirmation.
5. **Multilingual defensive filter** — Polylang/WPML detected via `function_exists`;
   candidate sets restricted to source-post language. `semantic_posts_disable_language_filter`
   override.
6. **WP.org submission hygiene** — Input sanitization, `wp_nonce` on every form, output
   escaping on every template variable, i18n on every user-facing string, no
   `eval`/`base64_decode` for code execution, no GPL-incompatible bundled libraries.
7. **Filter/action public surface** — Filters and actions exposed in v1 are frozen on
   launch (ADR-0007 treats as additive-only public contract).
8. **Observability as audit feature** — Powers both UJ-4 (owner confidence) and UJ-5 (host
   engineer 30-second verification). Must be self-evident, not just instrumented.
9. **Clean uninstall** — `delete_post_meta` over all `_sp_*` keys + option removal.
   Deactivation preserves data (re-enable continues from existing index).
10. **Disposable-MVP discipline** — Architecture optimizes for time-to-ship over future
    extensibility. The 1-week budget and 90-day kill criterion (Brief §F) are load-bearing
    on every component-boundary decision.

### Open Architecture Questions Inherited from PRD §8

- **Q3** — API key encryption-at-rest pattern (settings-option storage scheme).
- ~~**Q5**~~ — **Resolved by ADR-0008** during this workflow (grilling pass 2026-05-20).
  Crawler per-update budget validated at ~70 ms on Reference env (visit budget 300 × 47 µs
  + batched postmeta read).
- **Q11** — Verification-pass drift threshold metric and value.
- **Q12** — Random-sample size in crawler candidate sets (already partially answered by
  ADR-0008's L=5 entry points for insert mode; remaining tuning concerns the warm crawler's
  exploration sample size — currently the PRD's ~10 default working assumption).
- **Spike-surfaced cliff (resolved)** — N>5k corpus handling addressed by ADR-0008's phased
  cold start. Brief's "200–10,000 posts" range preserved without positioning changes.

### ADRs Created or Touched During This Workflow

- **ADR-0008** — Phased cold start: brute-force bootstrap (≤200 posts) + graph-traversal kNN
  (>200 posts) with visit budget 300 and L=5 random entry points. Supersedes the "Cold start"
  subsection of ADR-0004; warm crawler and verification pass in ADR-0004 are unchanged. PRD
  §3 / §4.2 / §6.1 / §8 / §15 patched surgically to reflect the new design.

## Starter Template Evaluation

### Primary Technology Domain

WordPress plugin — PHP 8.0+, distributed via WordPress.org. The "starter" landscape here is scaffolds, not opinionated frameworks. Web-application starter heuristics (Next.js, T3, Vite, etc.) do not apply.

### Starters Considered

| Option | What it provides | Learning cost | Fit for disposable 1-week MVP |
|---|---|---|---|
| **`wp scaffold plugin` (WP-CLI built-in)** | Main plugin file with headers, `includes/`, `readme.txt`, `.pot` setup, optional PHPUnit + WP test framework via `wp scaffold plugin-tests` | Zero — canonical ecosystem command | ✅ High. Three commands, canonical scaffold expected by WP.org reviewers |
| **WPPB (WordPress Plugin Boilerplate)** | Full OOP structure (`Plugin_Name`, `_Admin`, `_Public`, `_Loader`), admin/public separation, i18n, hooks loader pattern | Medium — WPPB-specific conventions | ⚠️ Overkill. WPPB's admin/public structural split does not map to this plugin's indexing-vs-render path separation (which is path-based, not surface-based). More boilerplate to learn and prune than value gained. |
| **Hand-rolled (no scaffold)** | Total control; Composer PSR-4 + main file + test harness assembled manually (~30 min setup) | Zero | ✅ Acceptable. But reinvents what `wp scaffold plugin` provides for free. |
| **Roots/plugin-skeleton** | PSR-4, Composer, modern PHP patterns | Medium | ❌ Optimized for developer-tool plugins, not WP.org-distributed consumer plugins. Non-canonical conventions raise friction during WP.org review. |

### Selected Starter: `wp scaffold plugin` + Composer PSR-4 overlay

**Rationale:**

1. **Canonical WP.org.** Output matches what plugin reviewers expect; reduces review friction (NFR-WPORG-1).
2. **Zero learning curve.** Everything produced is vanilla WordPress convention.
3. **Composer overlay (PSR-4)** provides modern organisation for the ~15 architectural components without conflicting with the canonical structure.
4. **Test harness included.** `wp scaffold plugin-tests` produces PHPUnit + WP test framework + GitHub Actions — exactly what's needed to test the crawler, render path, and indexing pipeline against the Reference environment (PRD §9.5) via Docker.

### Initialization Commands

```bash
# 1. Scaffold the plugin
wp scaffold plugin semantic-posts \
  --plugin_name="SemanticPosts" \
  --plugin_description="Related posts that are actually related, without slowing your site down." \
  --plugin_author="Christinoleo" \
  --plugin_uri="https://semanticposts.com" \
  --activate=false

# 2. Add PHPUnit + WP test framework
wp scaffold plugin-tests semantic-posts

# 3. Add Composer for PSR-4 autoload
cd wp-content/plugins/semantic-posts
composer init --name="christinoleo/semantic-posts" --type=wordpress-plugin --no-interaction
# Then edit composer.json to add PSR-4 mapping: "SemanticPosts\\": "src/"
mkdir src
```

### Architectural Decisions Provided by Starter

**Language & Runtime:** PHP 8.0+ (WordPress baseline). No frontend JS framework — render path is zero-JS per NFR-PERF-3. Admin pages may use vanilla JS for the observability panel and cost preview.

**Code Organization:** WordPress convention + PSR-4 namespace `SemanticPosts\\` rooted at `src/`:

- `semantic-posts.php` — main plugin file (WP headers, bootstrap, Composer autoload include)
- `src/` — plugin code (Crawler, Provider, Resolver, etc. — detailed in step-06)
- `includes/` — WP-canonical includes (`uninstall.php`, deactivation hooks)
- `templates/related-posts.php` — render template per ADR-0007
- `languages/` — `.pot` and translation files
- `tests/` — PHPUnit + WP test framework
- `readme.txt` — WP.org listing format (NFR-WPORG-5)
- `vendor/` — Composer (gitignored, regenerated via `composer install`)
- `composer.json` — PSR-4 mapping + dev dependencies

**Build Tooling:** None at build time. Plugin ships pure PHP — no transpile, no bundler. `composer install` is dev-only; the zip distributed to WP.org includes `vendor/` (Composer autoload generated, no runtime dependencies beyond PHP+WordPress core).

**Testing Framework:** PHPUnit 9.x + WordPress test framework (via `wp scaffold plugin-tests`). Integration tests run against a containerised WP fixture matching the Reference environment from PRD §9.5 (1 vCPU, 1GB RAM, PHP 8.0, MySQL 5.7, `WP_MEMORY_LIMIT=256M`, no opcache tuning, no object cache).

**Linting/Formatting:** PHP_CodeSniffer with WordPress Coding Standards (WPCS) ruleset, configured to satisfy WP.org review hygiene (NFR-WPORG-1, NFR-WPORG-2). Pre-commit hook via Composer scripts.

**Development Experience:** Local WordPress via `wp-env` or `wp-now`. Reference environment reproduced via Docker Compose for performance benchmarking (NFR-MKT-1 deliverable).

**Note:** Project initialization using the three commands above is the first implementation story (handed off to the dev session).

## Core Architectural Decisions

### Decision Priority Analysis

**Critical (block implementation):**
- API key encryption-at-rest pattern (A1)
- WP-Cron orchestration shape (B1)
- Resumability state storage (B2)
- Error handling pattern (B3)
- Embedding provider abstraction (C1)

**Important (shape architecture):**
- Cosine compute primitive (C2)
- Cost calculation/preview (C3)
- Verification pass threshold (D1)
- Warm crawler random sample size (D2)

**Standard WordPress conventions (recorded, not re-decided):**
- i18n text domain, admin JS approach, REST/AJAX surface, activation/deactivation hooks (Group E)

**Already locked by upstream ADRs and PRD (referenced, not re-decided):**
- Postmeta storage (ADR-0003), regeneration triggers (ADR-0002), Indexable Text (ADR-0001), warm crawler + phased cold start (ADR-0004 + ADR-0008), model versioning (ADR-0005), ranking modes (ADR-0006), rendering contract (ADR-0007), platform floor (PRD §13), starter scaffold (step-03).

### Security & Secrets (Group A)

**A1 — API key encryption-at-rest.** AES-256-CBC with a key deterministically derived from `AUTH_SALT` (constant) via `hash_hmac('sha256', 'sp_api_key', AUTH_SALT, true)` for the key material, plus a random per-value IV stored alongside the ciphertext (`base64({iv}:{ciphertext})` in the settings option). Implemented in `\SemanticPosts\Security\ApiKeyStorage`. Reversible across all WordPress installs without third-party libraries.

**A2 — Capability checks.** `manage_options` on all admin routes (settings, observability panel buttons, AJAX endpoints, WP-CLI commands). Standard WordPress convention; no deviation.

### Background Processing Shape (Group B)

**B1 — WP-Cron orchestration.** Single hourly event `semantic_posts_cron_tick` with **work-stealing drain**: each tick fires `\SemanticPosts\Indexing\TickProcessor::run()`, which processes:

1. Cold-start batch (if active) — up to 50 posts per tick per ADR-0008.
2. Dirty queue (if any) — up to 50 posts per tick per FR-1.
3. Verification pass (if due) — runs once per week independently of normal indexing.

Single registered event keeps the WordPress cron table clean. Cron orchestration logic centralized in one class for testability.

**B2 — Resumability state storage.** Single **non-autoloaded** option `_sp_state` (registered via `add_option(..., '', 'no')`). Persistent across cache flushes; excluded from autoload so it does not count against NFR-HOST-5 (1KB autoloaded option ceiling). Structure:

```php
[
  'cold_start' => ['phase' => 'bootstrap'|'graph_knn'|'idle', 'last_processed_id' => 12345, 'started_at' => 1700000000],
  'verification' => ['last_run' => 1700000000, 'next_due' => 1700604800, 'last_mrd' => 1.2],
  'dirty_queue_count' => 7,
]
```

Estimated peak size ~500 bytes.

**B3 — Error handling pattern.** Native `error_log` + `WP_DEBUG_LOG`, with a centralized formatter:

```php
\SemanticPosts\Logging::warn(string $message, array $context = []);
\SemanticPosts\Logging::error(string $message, array $context = []);
```

Format: `[SemanticPosts][LEVEL] {message} {context as json}`. Respects NFR-IDX-6 (fewer than 10 lines per day under normal operation) — `info` calls are gated behind a `SEMANTIC_POSTS_VERBOSE` constant.

### Compute & Data Abstractions (Group C)

**C1 — Embedding provider abstraction.**

```php
namespace SemanticPosts\Embeddings;

interface Provider {
    public function name(): string;             // 'openai/text-embedding-3-small'
    public function embed(string $text): array; // returns float[] dim=1536; throws RetryableException | FatalException
    public function maxInputTokens(): int;      // for truncation at indexable-text composition time
    public function costPerMillionTokens(): float;
}

final class OpenAIProvider implements Provider { /* v1 sole implementation */ }
```

- Retry / backoff logic lives outside the provider, in `\SemanticPosts\Indexing\EmbedJob`. Provider raises classified exceptions; job decides whether to enqueue retry.
- Rate limiting (1 req/sec per NFR) lives in `\SemanticPosts\Indexing\RateLimiter`, applied in the batch loop. Stateless (timing handled per batch, not per-tick).

**C2 — Cosine compute primitive.**

```php
namespace SemanticPosts\Embeddings;

final class Vector {
    public static function encode(array $floats): string;        // base64(pack('f*', ...))
    public static function decode(string $b64): \SplFixedArray;  // SplFixedArray<float>
    public static function dot(\SplFixedArray $a, \SplFixedArray $b): float; // == cosine for unit vectors
}
```

- Static methods on a class (WordPress autoload convention preferred over namespaced functions).
- `SplFixedArray<float>` for decoded storage (16 bytes/element vs ~80 for regular PHP array) — validated by Spike 0001 and required for ADR-0008's memory arithmetic to hold.
- Explicit `for` loop in `dot()`, not `array_sum(array_map(...))` — per-pair cost matters at high call volume.

**C3 — Cost calculation / preview.**

```php
final class CostCalculator {
    private const PRICING = [
        'openai/text-embedding-3-small' => 0.02 / 1_000_000, // $/token
        'openai/text-embedding-3-large' => 0.13 / 1_000_000, // Pro candidate
    ];
    public function estimateForPosts(int $count, string $model, int $avgTokensPerPost = 1000): float;
}
```

- Pricing table is a hardcoded constant, exposed via filter `semantic_posts_pricing_table` so future OpenAI price changes can ship as a small dot-release without touching consumers.
- `avgTokensPerPost = 1000` default per addendum §C.1. Filter `semantic_posts_avg_tokens_per_post` exposed for refinement once measurement infrastructure exists.

### Constants Inherited from Open Questions (Group D)

**D1 — Verification pass drift metric and threshold.** **Mean Rank Disagreement (MRD)** with **threshold 1.5**. For each of M=20 random sampled posts:

- Compute brute-force top-5 against the full corpus.
- Compute (via the rendered `_sp_related`) the graph's top-5.
- Per post, compute the Spearman footrule between the two top-5 sets.
- MRD = mean footrule across the M samples.
- If MRD > 1.5, surface an admin notice: *"Recommendations may have drifted — consider a full reindex."*

Threshold filter `semantic_posts_verification_threshold` exposed for empirical tuning.

**D2 — Warm crawler random exploration sample size.** **10 posts** added to the candidate set on top of `_sp_related` ∪ neighbors-of-those ∪ `_sp_inbound`, per PRD's working assumption. Filter `semantic_posts_crawler_exploration_sample_size` exposed.

### WordPress Conventions (Group E)

- **Text domain:** `semantic-posts` (matches plugin slug; required for WP.org translation packs).
- **Admin JS:** vanilla JavaScript, no jQuery dependency. Observability panel uses native `fetch()` for AJAX; settings page uses native form behaviour + small `<script>` for the cost-preview live update.
- **AJAX endpoints:** via `admin-ajax.php` with `wp_nonce` and `current_user_can('manage_options')` checks. Actions: `semantic_posts_run_indexing_now`, `semantic_posts_retry_failed`, `semantic_posts_run_verification_now`. No public REST API endpoints in v1.
- **Activation hook:** register cron event(s), schedule first-run cold-start gate, set default settings option if absent.
- **Deactivation hook:** clear all scheduled events via `wp_clear_scheduled_hook('semantic_posts_cron_tick')`. Preserve all data (re-activation resumes from existing index per ADR-0003).
- **Uninstall:** `includes/uninstall.php` per WordPress convention. Removes all `_sp_*` postmeta in batched `DELETE` statements + the settings option + the `_sp_state` option per FR-12.

### Decisions Pending Empirical Validation

This is a registry of constants and heuristics chosen with low-evidence reasoning during planning. Each is a candidate for revision once real-world feedback or evaluation data exists post-launch. Reviewers at Day 30 / Day 90 retros should walk this list.

| ID | Decision | Initial value | Why this value | Signal that would justify revision | Where to observe |
|---|---|---|---|---|---|
| **EV-01** | Bootstrap phase cap (ADR-0008 N_b) | 200 posts | Graph density estimate; small enough for trivial memory | Cold-start telemetry shows Phase 2 walks landing on posts not in Phase 1's top-K disproportionately, OR support tickets describe poor relevance for newly-installed corpora 200-500 posts | Verification pass MRD on first run; user support; manual test on staging |
| **EV-02** | Visit budget per insert (ADR-0008 B_v) | 300 nodes | NSW literature 95–99% recall@5; comfortably under 100 ms target | Verification pass MRD persistently > 1.0; OR telemetry shows walks consistently exhausting budget without converging (= too low); OR walks always finishing in < 100 nodes (= too high) | Verification pass; per-insert visit-count telemetry (post-v1 addition) |
| **EV-03** | Random entry points (ADR-0008 L) | 5 | NSW common default | Verification MRD high specifically on small clusters / topical isolates | Verification pass; per-insert telemetry |
| **EV-04** | Warm crawler exploration sample (D2) | 10 | PRD working assumption | Sites with broad topic spread complain about narrow recommendations; verification pass MRD higher than NFR-QUAL targets | NFR-QUAL-1 eval; support; verification pass |
| **EV-05** | Verification pass MRD threshold (D1) | 1.5 | Educated guess from HNSW literature on recall@5 ≈ 95% | After first 30 days: if threshold consistently fires false positives (graph is fine but threshold is too tight), OR never fires while users complain (threshold is too loose) | Observability panel; user support tickets |
| **EV-06** | Verification pass sample size (FR-5) | M=20 posts/week | Brief working assumption | If MRD signal is too noisy week-to-week (= M too small), OR if verification runtime exceeds reasonable cron-tick budget (= M too large) | Observability panel cron timing |
| **EV-07** | Indexing batch size (FR-2, NFR-IDX-1) | 50 posts / tick | Brief default | Cron ticks timing out before completing batches (= too large); OR cold start consistently slower than NFR-IDX-1 (= maybe too small with API rate spacing) | Observability panel cron timing |
| **EV-08** | Inter-batch pause | 1 second | OpenAI rate-limit heuristic | OpenAI 429 errors observed in steady state (= too aggressive); OR cold start dominated by sleep time on small corpora (= too conservative) | `error_log` + observability panel |
| **EV-09** | Indexable Text title repetition (ADR-0001) | 3× | SEO intuition | Cross-category discovery rate (SM-C2) consistently < 20% OR > 50% across diverse sites; OR NFR-QUAL-1 eval shows title-dominated false matches | NFR-QUAL-1 eval; SM-C2; user support |
| **EV-10** | Indexable Text truncation cap (ADR-0001) | ~6500 words | Token limit headroom for `text-embedding-3-small` | Long-form sites (recipe blogs, knowledge bases) complain that conclusions don't influence recommendations | User support |
| **EV-11** | Fresh-first decay (FR-4a) | 180 days | Default working assumption | News sites complain that 1-month-old posts are recommended above week-old; OR knowledge-base sites complain that decay buries evergreen content even when "Most relevant" is selected | User support; mode-specific feedback |
| **EV-12** | MMR λ (FR-4a) | 0.7 | Default working assumption | Diverse mix mode produces visibly off-topic items (λ too low) or near-identical items (λ too high) | User support; mode-specific feedback |
| **EV-13** | Quality-bounded score threshold (FR-8) | 0.3 cosine | Default working assumption (opt-in feature) | Sites that enable it surface unrelated items (threshold too low) OR get empty lists frequently (threshold too high) | User support among opt-in users |
| **EV-14** | Avg tokens / post (C3) | 1000 | Addendum §C.1 estimate | Real cost charged consistently > 20% off the cost preview | OpenAI billing comparison |
| **EV-15** | OpenAI pricing values (C3) | $0.02/1M tokens (small), $0.13/1M (large) | May 2026 verified | OpenAI publishes new pricing | Manual check during quarterly maintenance |

**Operating rule for revisions:** any constant in this registry may be changed in a minor release without breaking the public contract (ADR-0007 covers HTML/CSS/filter surface; algorithmic constants are not part of that contract). Filter hooks are exposed where revision is most likely.

## Implementation Patterns & Consistency Rules

Patterns below exist to prevent divergence between the BMAD planning session and the parallel dev session (and between any future AI agents implementing pieces in isolation). Focus is on points with real risk of inconsistency, not exhaustive prescription.

### Conflict Points Identified (high risk of divergence)

1. PSR-4 file naming (`Foo.php`) vs WPCS file naming (`class-foo.php`)
2. Postmeta access scattered across subsystems vs centralized through owning class
3. WordPress hook / filter naming consistency
4. Nonce / escape / sanitize discipline (silent skip breaks NFR-WPORG-1)
5. Cron callback scatter vs single TickProcessor
6. Exception classification consistency across the indexing pipeline

### Naming Conventions

**Files & Classes:**

| Type | Convention | Example |
|---|---|---|
| Namespaced PSR-4 code in `src/` | PascalCase, one class per file, file matches class name | `src/Embeddings/Vector.php` → `\SemanticPosts\Embeddings\Vector` |
| Hand-rolled WP-canonical files (root + `includes/`) | WPCS snake_case | `semantic-posts.php`, `includes/uninstall.php` |
| Templates | WPCS snake_case (theme overrides expect this) | `templates/related-posts.php` |
| Tests | PSR-4 mirroring source | `tests/Embeddings/VectorTest.php` |

WPCS configured in `phpcs.xml.dist` to **exempt `src/` from `WordPress.Files.FileName`** rule (modern plugin practice, accepted by WP.org reviewers in 2026).

**Identifiers:**

| Identifier | Convention | Example |
|---|---|---|
| Namespace root | `SemanticPosts\` | `SemanticPosts\Indexing\Crawler` |
| Method names | camelCase (PSR-12) | `embed()`, `runCronTick()` |
| Property names | camelCase, no Hungarian prefix | `private float $score;` |
| Constants (class) | UPPER_SNAKE | `const BATCH_SIZE = 50;` |
| Constants (global, plugin-level) | `SEMANTIC_POSTS_*` | `SEMANTIC_POSTS_VERSION`, `SEMANTIC_POSTS_DIR` |
| Database keys (postmeta, options) | `_sp_*` (locked by ADR-0003) | `_sp_embedding`, `_sp_related`, `_sp_state` |
| WordPress hooks registered by us | `semantic_posts_*` | `do_action('semantic_posts_before_render')` |
| WP-CLI commands | `semantic-posts {verb}` (locked by FR-11) | `wp semantic-posts index` |
| Cron event names | `semantic_posts_*` | `semantic_posts_cron_tick` |
| AJAX action names | `semantic_posts_*` | `wp_ajax_semantic_posts_run_indexing_now` |
| Text domain | `semantic-posts` | `__('Indexing complete', 'semantic-posts')` |
| Nonce action names | `semantic_posts_{verb}` | `wp_create_nonce('semantic_posts_save_settings')` |

### Structure Patterns

**`src/` organization — by subsystem, not by layer:**

```
src/
├── Bootstrap.php              # plugin lifecycle, hook registration entry point
├── Embeddings/                # provider abstraction + cosine + encoding
│   ├── Provider.php           # interface
│   ├── OpenAIProvider.php
│   ├── Vector.php
│   └── Exception/
│       ├── RetryableException.php
│       └── FatalException.php
├── Indexing/                  # hash-diff trigger, jobs, cron, crawler, cold-start
│   ├── IndexableTextBuilder.php
│   ├── HashDiffDetector.php
│   ├── DirtyQueue.php
│   ├── EmbedJob.php
│   ├── RateLimiter.php
│   ├── Crawler.php            # warm + insert modes (ADR-0004 + ADR-0008)
│   ├── ColdStart.php          # phase manager
│   ├── VerificationPass.php
│   └── TickProcessor.php      # single cron entry point
├── Display/                   # rendering + auto-injection + shortcode
│   ├── Template.php
│   ├── ContentFilter.php
│   ├── Shortcode.php
│   └── SourceResolver.php     # semantic / category-fallback / none
├── Ranking/                   # three modes
│   ├── Mode.php               # interface
│   ├── MostRelevantMode.php
│   ├── FreshFirstMode.php
│   └── DiverseMixMode.php
├── Settings/                  # admin page + cost preview
│   ├── SettingsPage.php
│   ├── SettingsRepository.php
│   └── CostCalculator.php
├── Observability/             # 24h panel + counters + activity log
│   ├── ObservabilityPanel.php
│   ├── Metrics.php
│   └── ActivityLog.php
├── Security/
│   └── ApiKeyStorage.php      # AES-256-CBC encryption
├── Logging/
│   └── Logging.php            # static centralized formatter
├── CLI/                       # WP-CLI command handlers
│   └── Commands.php
└── Lifecycle/
    ├── Activator.php
    └── Deactivator.php
```

**Tests:** mirror `src/` 1:1 under `tests/`. No co-location. Test classes suffix `Test`.

**Cross-cutting:** subsystems may depend on lower-level subsystems (Indexing → Embeddings, Display → Ranking). No circular dependencies. No domain logic in Bootstrap or Lifecycle — they only wire hooks and call subsystem entry points.

### Format Patterns

**Postmeta data formats (locked, do not re-invent):**

| Key | Format | Producer | Consumer |
|---|---|---|---|
| `_sp_embedding` | `base64(pack('f*', ...))` via `Vector::encode()` | `EmbedJob` | `Vector::decode()`, `Crawler` |
| `_sp_related` | JSON `[{id:int, score:float}, ...]` | `Crawler` | `SourceResolver`, render |
| `_sp_inbound` | JSON `[int, int, ...]` (post IDs only) | `Crawler` propagation | `Crawler` candidate construction |
| `_sp_text_hash` | hex `md5(...)` | `HashDiffDetector` | `HashDiffDetector` comparison |
| `_sp_dirty` | `'1'` or absent | `HashDiffDetector` | `DirtyQueue` |

**Single-writer invariant for `_sp_*` keys.** All `_sp_*` access goes through the owning subsystem. Agents MUST NOT call `get_post_meta`/`update_post_meta` for `_sp_*` keys directly outside the producing subsystem — prevents encoding drift.

- `Vector` owns `_sp_embedding` codec.
- `Crawler` owns `_sp_related` + `_sp_inbound` writes.
- `HashDiffDetector` owns `_sp_text_hash` + `_sp_dirty` mutations.
- Other code reads through these classes (which expose decoded values).

**AJAX responses:** use WordPress's `wp_send_json_success([...])` and `wp_send_json_error([...])`. Do not invent custom envelopes.

**Settings option:** single associative array under key `semantic_posts_settings`, autoloaded, ≤1KB (NFR-HOST-5). Fields documented in `SettingsRepository`. No nested deep structures.

**State option:** single associative array under key `_sp_state`, NON-autoloaded (per Group B2 decision). Owned by `TickProcessor` and `ColdStart`.

**Exceptions:** all exceptions raised by `Embeddings\Provider` are either `RetryableException` (HTTP 429, 5xx, timeout, network) or `FatalException` (HTTP 4xx other than 429, malformed response, no API key configured). `EmbedJob` is the single point that catches both and routes to retry queue vs. failed-post-flag.

### Process Patterns

**Centralized cron entry point.** Only ONE registered cron callback: `\SemanticPosts\Indexing\TickProcessor::run()`. Any background work registers itself as a `TickProcessor` participant, not as a separate cron event. Single point to debug "what fired in the last tick."

**Centralized hook registration.** All `add_action`/`add_filter` calls live in `Bootstrap::registerHooks()`. Subsystems expose methods; Bootstrap wires them. Agents MUST NOT call `add_action` from inside subsystem classes — prevents "where is this hook attached?" hunts.

**Mandatory boundary discipline:**

| At boundary | MUST do |
|---|---|
| Receive AJAX request | `check_ajax_referer()` + `current_user_can('manage_options')` BEFORE anything else |
| Receive form POST | `wp_verify_nonce()` + cap check |
| Receive WP-CLI args | `WP_CLI::error()` on invalid input |
| Save user input to options/postmeta | `sanitize_text_field()` / `sanitize_email()` / `absint()` / etc. as appropriate |
| Render HTML | `esc_html()` / `esc_attr()` / `esc_url()` at the echo point, NOT at storage |
| Translate user-facing string | `__()` or `esc_html__()` with text domain `semantic-posts` |

Reviewer hygiene non-negotiable per NFR-WPORG-1 / -4.

**Error handling discipline:**

- Subsystems raise typed exceptions, do NOT call `Logging::error()` directly except for unrecoverable code paths.
- `TickProcessor::run()` is the single try/catch that decides what to log + what to retry + what to mark failed.
- User-facing error messages translated via `__()` and surfaced via admin notices (`admin_notices` hook). NEVER `var_dump` or `wp_die` in production paths.
- `Logging::*` calls always include `['post_id' => ..., 'subsystem' => ...]` context.

**Canonical capability + nonce check pattern:**

```php
public function handleRunIndexingNow(): void {
    check_ajax_referer( 'semantic_posts_run_indexing_now', '_ajax_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'semantic-posts' ) ], 403 );
    }
    // ... business logic ...
}
```

Every admin handler matches this shape. Deviation = WP.org review reject.

### Anti-Patterns (explicit DO NOT)

| ❌ Anti-pattern | ✅ Correct pattern | Reason |
|---|---|---|
| `get_post_meta($id, '_sp_embedding', true)` decoded inline | `Vector::decode(get_post_meta($id, '_sp_embedding', true))` | Encoding drift risk |
| `add_action()` inside subsystem class constructor | Subsystem exposes methods; `Bootstrap` registers | Hook archaeology |
| `error_log()` direct call from subsystem | `Logging::warn($msg, $ctx)` | Format drift, NFR-IDX-6 |
| `wp_schedule_event('semantic_posts_my_thing', ...)` | Add work to `TickProcessor::run()` participants | Cron event sprawl |
| `try { ... } catch (\Throwable $t) {}` (silent swallow) | Classified exception + targeted catch in `TickProcessor` | Hidden failures |
| `echo $value;` | `echo esc_html( $value );` | XSS / WP.org reject |
| `$_POST['key']` direct usage | `sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) )` after nonce check | Injection / WP.org reject |
| Cross-subsystem `_sp_*` postmeta write | Call the owning subsystem's class method | Single-writer invariant |
| Two-level deep nested option structure | Flat associative array with predictable keys | 1KB autoloaded ceiling |
| JS via jQuery (`$('#btn').on(...)`) | Vanilla JS with `addEventListener` | Bundle size, no `jquery` dep |

### Enforcement Guidelines

**Automated:**
- PHP_CodeSniffer with WPCS configured in `phpcs.xml.dist` — runs in CI and via pre-commit.
- PHPUnit tests cover the canonical encode/decode roundtrip, exception classification, and rendering escape behavior.
- Custom WPCS sniffs for `_sp_*` boundary may be added if a single-writer regression appears (deferred until needed).

**Manual:**
- All cross-session PR reviews check the Anti-Patterns table above.
- The Revisit Registry (EV-01 through EV-15) is the canonical place to amend tuning constants — don't scatter them in random files.

**Pattern update process:**
- New pattern: amend this section + a corresponding example test. ADR only if hard-to-reverse.
- Pattern conflict found at implementation time: dev session reports back to planning session via a `[PATTERN-CONFLICT]` note; planning session decides amendment vs. exception.

## Project Structure & Boundaries

### Complete Project Directory Structure

```
semantic-posts/                          # plugin root = wp-content/plugins/semantic-posts/
├── semantic-posts.php                   # main plugin file (WP headers, bootstrap, Composer autoload)
├── readme.txt                           # WP.org listing (NFR-WPORG-5)
├── README.md                            # GitHub/dev docs
├── LICENSE                              # GPLv2+ (NFR-WPORG-1)
├── composer.json                        # PSR-4 mapping + dev dependencies
├── composer.lock
├── phpunit.xml.dist                     # PHPUnit config
├── phpcs.xml.dist                       # WPCS ruleset (src/ exempted from FileName rule)
├── .gitignore                           # excludes /vendor (dev), /node_modules, .env
├── .editorconfig
├── .wp-env.json                         # local dev WP fixture (Automattic)
├── .github/
│   └── workflows/
│       ├── ci.yml                       # phpcs + phpunit on push/PR
│       └── benchmark.yml                # Reference env perf benchmark (NFR-MKT-1 deliverable)
├── docker/                              # Reference env reproduction (PRD §9.5)
│   ├── Dockerfile                       # PHP 8.0 + MySQL 5.7 + WP fixture
│   └── docker-compose.yml               # 1 vCPU / 1GB / 256M PHP limits
├── includes/
│   └── uninstall.php                    # FR-12 — removes all _sp_* postmeta + settings option
├── languages/
│   └── semantic-posts.pot               # NFR-WPORG-4
├── templates/
│   └── related-posts.php                # ADR-0007 default template (theme-overridable)
├── assets/
│   ├── css/
│   │   └── semantic-posts.css           # default front-end styles; system fonts only
│   ├── js/
│   │   ├── admin-observability.js       # vanilla JS — fetch() for AJAX
│   │   └── admin-settings.js            # cost preview live update
│   └── images/
│       ├── icon-128x128.png             # WP.org listing
│       ├── icon-256x256.png
│       └── banner-1544x500.png
├── src/                                 # PSR-4 namespace SemanticPosts\
│   ├── Bootstrap.php                    # plugin lifecycle, hook registration entry point
│   ├── Embeddings/
│   │   ├── Provider.php                 # interface
│   │   ├── OpenAIProvider.php           # v1 impl
│   │   ├── Vector.php                   # encode/decode/dot
│   │   └── Exception/
│   │       ├── RetryableException.php
│   │       └── FatalException.php
│   ├── Indexing/
│   │   ├── IndexableTextBuilder.php     # ADR-0001 composition
│   │   ├── HashDiffDetector.php         # ADR-0002 trigger
│   │   ├── DirtyQueue.php
│   │   ├── EmbedJob.php                 # retry queue + classification
│   │   ├── RateLimiter.php              # 1 req/sec
│   │   ├── Crawler.php                  # warm + insert modes (ADR-0004 + ADR-0008)
│   │   ├── ColdStart.php                # phase manager (bootstrap → graph_knn)
│   │   ├── VerificationPass.php         # FR-5 + MRD threshold (D1)
│   │   └── TickProcessor.php            # single cron entry point
│   ├── Display/
│   │   ├── Template.php                 # invokes templates/related-posts.php
│   │   ├── ContentFilter.php            # FR-6 auto-injection on the_content
│   │   ├── Shortcode.php                # FR-7 [semantic_posts]
│   │   └── SourceResolver.php           # semantic / category-fallback / none
│   ├── Ranking/
│   │   ├── Mode.php                     # interface
│   │   ├── MostRelevantMode.php         # pure cosine
│   │   ├── FreshFirstMode.php           # recency decay (EV-11)
│   │   └── DiverseMixMode.php           # MMR (EV-12)
│   ├── Settings/
│   │   ├── SettingsPage.php             # FR-9 admin page
│   │   ├── SettingsRepository.php       # single autoloaded option
│   │   └── CostCalculator.php           # pricing table + estimate (EV-14/15)
│   ├── Observability/
│   │   ├── ObservabilityPanel.php       # FR-10 panel render
│   │   ├── Metrics.php                  # 24h counters aggregation
│   │   └── ActivityLog.php              # tail-style recent events
│   ├── Security/
│   │   └── ApiKeyStorage.php            # AES-256-CBC + AUTH_SALT
│   ├── Logging/
│   │   └── Logging.php                  # static warn/error/info formatters
│   ├── CLI/
│   │   └── Commands.php                 # FR-11 — index, reindex, process-dirty, verify, retry-failed, status
│   └── Lifecycle/
│       ├── Activator.php                # register cron, default settings
│       └── Deactivator.php              # clear schedules; preserve data
├── tests/
│   ├── bootstrap.php                    # WP test framework bootstrap
│   ├── Embeddings/
│   │   ├── VectorTest.php               # encode/decode roundtrip + dot accuracy
│   │   └── OpenAIProviderTest.php       # HTTP mocked; exception classification
│   ├── Indexing/
│   │   ├── IndexableTextBuilderTest.php
│   │   ├── HashDiffDetectorTest.php
│   │   ├── CrawlerTest.php              # warm + insert candidate sets
│   │   ├── ColdStartTest.php            # bootstrap / phase transition
│   │   ├── VerificationPassTest.php
│   │   └── TickProcessorTest.php        # work-stealing drain
│   ├── Display/
│   │   ├── TemplateTest.php             # ADR-0007 HTML structure assertions
│   │   ├── ContentFilterTest.php        # the_content position + dedupe with shortcode
│   │   ├── ShortcodeTest.php
│   │   └── SourceResolverTest.php       # semantic / category-fallback / none transitions
│   ├── Ranking/
│   │   ├── MostRelevantModeTest.php
│   │   ├── FreshFirstModeTest.php
│   │   └── DiverseMixModeTest.php       # MMR property tests
│   ├── Settings/
│   │   ├── SettingsRepositoryTest.php   # autoload size assertion
│   │   └── CostCalculatorTest.php
│   ├── Security/
│   │   └── ApiKeyStorageTest.php        # roundtrip + AUTH_SALT-derived key
│   ├── Performance/                     # ran via benchmark workflow, not on every PR
│   │   ├── RenderPathBenchmarkTest.php  # NFR-PERF-1/2/3/4 + cache compat
│   │   └── ColdStartBenchmarkTest.php   # NFR-IDX-1 + 5k corpus end-to-end
│   └── Integration/
│       ├── EndToEndIndexingTest.php     # save_post → embed → crawler → render
│       └── MultilingualFilterTest.php   # Polylang/WPML defensive filter
└── vendor/                              # Composer (gitignored in dev; shipped in WP.org zip)
```

### Requirements → Structure Mapping

| Capability | Primary subsystem | Key files |
|---|---|---|
| FR-1 hash-diff trigger | `Indexing` | `HashDiffDetector`, `DirtyQueue`, hooked from `Bootstrap` (`save_post`, `transition_post_status`) |
| FR-2 bulk index UI | `Indexing` + `Settings` | `SettingsPage` admin form → `ColdStart::start()` |
| FR-3 retry/failure | `Indexing` | `EmbedJob` catch → retry queue or failed-flag |
| FR-4 warm crawler | `Indexing` + `Ranking` | `Crawler::update()` + `Ranking\Mode` impl |
| FR-4a ranking mode select | `Settings` + `Ranking` | `SettingsRepository::getRankingMode()` → `Ranking\Mode` factory |
| FR-4b cold start | `Indexing` | `ColdStart` phase manager + `Crawler::insert()` |
| FR-5 verification + reindex | `Indexing` | `VerificationPass` + `Settings` "Reindex all" / "Retry failed" / "Verify now" |
| FR-6 auto-injection | `Display` | `ContentFilter` on `the_content` |
| FR-7 shortcode | `Display` | `Shortcode` on `[semantic_posts]` |
| FR-8 template rendering | `Display` | `Template` → `templates/related-posts.php` (theme-overridable) |
| FR-9 settings UI | `Settings` | `SettingsPage`, `SettingsRepository`, `CostCalculator` |
| FR-10 observability panel | `Observability` | `ObservabilityPanel`, `Metrics`, `ActivityLog` |
| FR-11 WP-CLI | `CLI` | `Commands` — thin handlers delegating to subsystems |
| FR-12 clean uninstall | `includes/uninstall.php` (NOT in `src/` — WP convention requires standalone file) |
| Multilingual defensive filter | `Display\SourceResolver` + `Indexing\Crawler` | Polylang/WPML detection via `function_exists` |
| Cost transparency | `Settings\CostCalculator` invoked by `SettingsPage`, `ColdStart`, `Reindex` confirmations |

### Architectural Boundaries

**Render path boundary (NFR-PERF discipline):**
- `Display\*` is the ONLY code that runs in a frontend request lifecycle.
- `Display\*` may invoke `SourceResolver` and load `_sp_related` via single `get_post_meta` + `WP_Query`.
- `Display\*` MUST NOT import or reference any class from `Indexing\*`, `Embeddings\*` (except `Vector` for read decoding, which is not invoked at render), or call any HTTP/network code.
- Static analysis enforcement candidate: a PHPStan rule blocking `Display\*` imports of `Indexing\*` and `Embeddings\OpenAIProvider`.

**Indexing path boundary (background only):**
- `Indexing\*`, `Embeddings\OpenAIProvider` may run only from WP-Cron (`TickProcessor`) or WP-CLI (`CLI\Commands`).
- Never registered as a hook callback that fires on a frontend request.

**Provider boundary:**
- `Embeddings\Provider` interface is the only API surface for embedding generation.
- All HTTP work is encapsulated in concrete `Provider` implementations.
- `Indexing\EmbedJob` consumes the interface, classifies exceptions, decides retry routing.

**Postmeta boundary (single-writer invariant, per Implementation Patterns):**
- `_sp_embedding` ← only `EmbedJob` writes, only `Vector` decodes
- `_sp_related` ← only `Crawler` writes, only `SourceResolver` reads
- `_sp_inbound` ← only `Crawler` writes, only `Crawler` reads
- `_sp_text_hash`, `_sp_dirty` ← only `HashDiffDetector` writes, only `DirtyQueue` reads

### Integration Points

**Internal communication:**
- Direct PHP class method calls within and across subsystems (no message bus, no events for internal coordination).
- WordPress action/filter hooks for **public extension surface only** (ADR-0007 filters/actions; not used for internal control flow).
- `Bootstrap::registerHooks()` is the only place `add_action`/`add_filter` lives.

**External integrations:**
- **OpenAI Embeddings API** — only consumer is `Embeddings\OpenAIProvider`. HTTP via `wp_remote_post()` (WordPress native, respects host proxy config).
- **WordPress hooks consumed:** `save_post`, `transition_post_status`, `wp_trash_post`, `the_content`, `init` (text domain), `admin_menu`, `admin_init`, `admin_enqueue_scripts`, `wp_ajax_*`.
- **WordPress hooks emitted:** all `semantic_posts_*` actions and filters per ADR-0007 contract.

**Data flow:**

```
save_post
   ↓
HashDiffDetector → DirtyQueue (postmeta _sp_dirty=1)
                              ↓ (hourly WP-Cron tick)
                       TickProcessor::run()
                              ↓
              ColdStart (if active) → Crawler::insert()
                                          ↓
              DirtyQueue                  → Crawler::update()
                                              ↓ uses Embeddings\OpenAIProvider (rate-limited)
                                              ↓ produces _sp_embedding, _sp_related, _sp_inbound
              VerificationPass (if due) → MRD check → admin notice if threshold exceeded


[Frontend request]
   the_content filter
   ↓
ContentFilter → SourceResolver
                    ↓ reads _sp_related (1 get_post_meta) + ranked post IDs (1 WP_Query)
                    ↓ semantic / category-fallback / none
Template (templates/related-posts.php)
   ↓
HTML output with data-sp-source attributes
```

### Development Workflow Integration

**Development server:** `wp-env start` provisions a local WordPress; plugin code mounted in-place via `.wp-env.json`. Hot-reload not relevant (PHP plugin, no transpile).

**Reference env benchmark:** `docker compose up --build` boots PHP 8.0 + MySQL 5.7 + WP with `WP_MEMORY_LIMIT=256M`, no opcache tuning, no object cache. Benchmark scripts under `tests/Performance/` produce the NFR-MKT-1 deliverable.

**CI pipeline (`.github/workflows/ci.yml`):**
1. `composer install --no-dev` (production install verification)
2. `composer install` (dev tools)
3. `composer run phpcs` (WPCS ruleset, blocking)
4. `composer run phpunit` (unit + integration; blocking)
5. WP plugin asset readiness check (icon sizes, readme.txt format) (blocking)

**Benchmark pipeline (`benchmark.yml`):** runs nightly on `main`. Outputs published to a `benchmarks/` branch consumed by the marketing site.

**Deployment:** `composer install --no-dev && zip -r semantic-posts.zip semantic-posts/ --exclude=tests/* --exclude=docker/* --exclude=.github/* ...` produces the WP.org submission zip.

## Architecture Validation Results

### Coherence Validation

**Decision compatibility.** ADRs 0001–0008 are mutually consistent (PRD decision-log validated at 100% post-redelta; ADR-0008 supersedes the relevant subsection of ADR-0004 only, propagation rules in ADR-0004 preserved). Step-04 decisions, step-05 patterns, and step-06 structure compose without contradicting any ADR. Locked-fill-gaps-only posture honored throughout.

**Pattern consistency.** Naming conventions (`semantic_posts_*` hooks, `_sp_*` postmeta, `SemanticPosts\` namespace, kebab-case CLI) are uniform across all FR coverage. Single-writer postmeta invariant aligns with boundary discipline. Centralized hook registration in `Bootstrap::registerHooks()` and centralized cron entry in `TickProcessor::run()` align with each other and with the "render-path vs indexing-path" hard wall.

**Structure alignment.** `src/` by-subsystem (not by-layer) enforces the indexing-vs-render boundary at the import level — a static-analysis rule blocking `Display\* → Indexing\*` imports is enforceable. Tests mirror src/ 1:1, CI matches.

### Requirements Coverage Validation

**Functional requirements:** all FR-1 through FR-12 mapped in step-06 "Requirements → Structure Mapping" table. No FR is orphaned.

**Non-functional requirements:**

| Group | Coverage status |
|---|---|
| PERF-1/2/3/4 | Render-path boundary + Performance benchmark tests + Reference env docker fixture |
| PERF-5 (cache compat) | Deterministic Template + SourceResolver = identical HTML per post |
| HOST-1/2 | External (host outreach) — process documented in PRD addendum §E, not architecture |
| HOST-3 (object-cache) | `get_post_meta`/`get_option` are automatically cached by WP — no special handling |
| HOST-4 (backup-safe) | Vector binary encoding + `semantic_posts_exclude_from_backup` filter (ADR-0003) |
| HOST-5 (≤1KB autoloaded) | `SettingsRepository` single autoloaded option + `_sp_state` non-autoloaded |
| IDX-1 (1000 posts <2min) | ADR-0008 arithmetic + Reference env benchmark; linear scaling from spike |
| IDX-2 (memory ceiling) | ADR-0008 phased design + 80% memory guard in `TickProcessor` |
| IDX-3 (resumable) | `_sp_state` non-autoloaded option + `ColdStart` phase manager |
| IDX-4 (cron-disabled fallback) | `CLI\Commands` + Observability "Run now" button |
| IDX-5 (API failure tolerance) | `EmbedJob` retry queue + `RetryableException`/`FatalException` classification |
| IDX-6 (log discipline) | `Logging` static formatter, `info` gated behind `SEMANTIC_POSTS_VERBOSE` |
| QUAL-1/2/3/4 | Algorithm choices (ADR-0001 + ADR-0006 + ADR-0008) + Verification pass (EV-04, EV-05 trackable) |
| ON-1/2/3/4 | Setup wizard in `Settings`, PHP 8.0+ baseline (composer.json), cost preview via `CostCalculator` |
| WPORG-1/2/3/4/5 | Boundary discipline (Implementation Patterns) + WPCS in CI + i18n with text domain `semantic-posts` + readme.txt scaffolded by `wp scaffold plugin` |
| WPORG-6 | Listing copy — marketing concern, captured outside plugin scope |
| MKT-1/2/3/4 | Marketing-site deliverables — out of plugin architecture scope, dependencies declared in PRD |

**Open PRD §8 questions, post-architecture:**

| OQ | Status |
|---|---|
| Q1 empty-state UX | Resolved pre-architecture (silent category fallback) |
| Q2 slug availability | PM/marketing — out of architecture |
| Q3 API key encryption | **Resolved here (step-04 A1):** AES-256-CBC + AUTH_SALT-derived key |
| Q4 batch size | Brief default 50; logged as EV-07 (revisit trigger) |
| Q5 crawler per-update budget | **Resolved by ADR-0008:** ~70 ms validated arithmetically |
| Q6 excerpt cap | ADR-0007 covers (default 160 chars + filter) |
| Q7 theme compat test bench | **Open at sprint-planning level**, not architecture: recommend Twenty Twenty-Four, Astra, Kadence, GeneratePress, Hello Elementor in the docker performance fixture |
| Q8 50k render perf | Out of v1 commitment (positioning capped at 10k) |
| Q9 host contact path | PM/marketing — out of architecture |
| Q10 UX pattern validity | Research — out of architecture |
| Q11 verification drift threshold | **Resolved here (step-04 D1):** MRD ≥ 1.5 |
| Q12 random sample size | **Resolved here (step-04 D2 + ADR-0008):** warm crawler 10, insert mode L=5 |

### Implementation Readiness Validation

**Decision completeness.** All critical decisions documented with rationale. PHP/MySQL/WP version floors stated. Composer + PSR-4 + WPCS-exempt-src configuration documented. Test framework chosen and bootstrap path documented.

**Structure completeness.** Project tree exhaustive (root files, includes, languages, templates, assets, src/, tests/, docker/, .github/). FR → subsystem mapping is total. Architectural boundaries enumerated.

**Pattern completeness.** Naming, structure, format, process, and anti-pattern conventions covered. Canonical capability+nonce check pattern given as concrete example. Enforcement guidelines (PHPCS + PHPUnit + manual review of Anti-Patterns table) documented.

### Gap Analysis Results

**Critical gaps:** none. No FR or load-bearing NFR is unsupported architecturally.

**Important gaps (sprint-planning-level, not architecture):**

- **G-01 — Theme compat test bench composition (PRD Q7).** Architecture provides the Docker reference fixture; the *list of themes* the benchmark runs against is a sprint-planning decision. Recommended starting list: Twenty Twenty-Four, Astra, Kadence, GeneratePress, Hello Elementor — covers block-based + classic + popular page-builders.
- **G-02 — Category-fallback ordering rule.** "Most recent posts in same category as current post" — explicit query is implementable (`WP_Query` with `category__in` + `orderby=date` + `order=DESC` + `posts_per_page=K` + `post__not_in=[current]`), but not enumerated in the FR mapping. Dev session can implement directly without back-and-forth.

**Nice-to-have gaps:**

- **G-03 — Static analysis level.** PHPStan/Psalm not explicitly chosen. Recommend PHPStan level 6 on `src/` only, level 4 on the rest. Defer to dev session.
- **G-04 — Admin page HTML structure.** ADR-0007 covers the frontend widget contract; admin pages are implementation detail. Dev session can pick within WP convention (Settings API + custom render).
- **G-05 — Test fixture generators.** No spec for generating embedding fixtures for crawler tests. Dev session can use `array_fill` with deterministic seeded values; not architecture-level.

### Architecture Completeness Checklist

**Requirements Analysis**
- [x] Project context thoroughly analyzed (step-02)
- [x] Scale and complexity assessed (step-02)
- [x] Technical constraints identified (step-02)
- [x] Cross-cutting concerns mapped (step-02)

**Architectural Decisions**
- [x] Critical decisions documented (step-04 Groups A–E + ADRs 0001–0008)
- [x] Technology stack fully specified (PHP 8.0+, MySQL 5.7+, WP 6.0+; Composer; PHPUnit; WPCS)
- [x] Integration patterns defined (Provider interface, hooks, exception classification)
- [x] Performance considerations addressed (Reference env, render-path boundary, ADR-0008)

**Implementation Patterns**
- [x] Naming conventions established (step-05)
- [x] Structure patterns defined (step-05 + step-06)
- [x] Communication patterns specified (step-05 process patterns + step-06 integration points)
- [x] Process patterns documented (step-05 — boundary discipline, error handling, canonical examples)

**Project Structure**
- [x] Complete directory structure defined (step-06)
- [x] Component boundaries established (step-06 boundaries section)
- [x] Integration points mapped (step-06 data flow diagram)
- [x] Requirements to structure mapping complete (step-06 FR mapping table)

### Architecture Readiness Assessment

**Overall Status:** **READY FOR IMPLEMENTATION**

All 16 checklist items checked. No Critical Gaps open. Important gaps (G-01, G-02) are sprint-planning-level and unblocking; nice-to-have gaps (G-03/04/05) are dev-session conveniences.

**Confidence level:** High.

**Key strengths:**

- Heavy upstream investment in ADRs (0001–0008) preempted most architecture-phase debates; this workflow filled targeted gaps rather than re-deriving decisions.
- ADR-0008 (grilling-derived during this workflow) unified the cold-start and warm-crawler implementations, reducing component count and validating PRD §8 Q5 numerically.
- Empirical validation registry (EV-01 through EV-15) makes guesses traceable and revision-cheap post-launch.
- Render-path / indexing-path boundary is enforceable at the static-analysis level, not just by convention.
- Disposable-MVP discipline preserved: scaffold is canonical (`wp scaffold plugin`), no premature abstractions, no provider-flexibility surface beyond the interface required.

**Areas for future enhancement (post-v1, in priority order):**

1. Empirical tuning of EV-01 through EV-15 once launch telemetry exists.
2. PHPStan level 8 on `src/` after the codebase stabilizes (probably v1.1).
3. Multi-evaluator quality eval (NFR-QUAL-1 currently single-evaluator) if support traffic indicates relevance complaints.
4. HNSW-style multi-layer graph (ADR-0008 Future Work) if Pro tier ever targets >10k posts.
5. ARIA / accessibility audit on the rendering contract (ADR-0007 Future Work).

### Implementation Handoff

**AI Agent (dev session) Guidelines:**

- Read this document end-to-end before scaffolding. ADRs 0001–0008 are authoritative for the topics they cover.
- Implementation Patterns section is binding — Anti-Patterns table is the WP.org review pre-flight.
- Empirical Validation Registry (EV-01 through EV-15) is the canonical place for tuning constants. Filters are exposed where revision is most likely.
- When in doubt about a constant, value, or behavior: ADR > PRD FR/NFR > CONTEXT.md glossary > this architecture doc > sprint-planning decision.
- Pattern conflicts found at implementation time: report back to planning session via a `[PATTERN-CONFLICT]` note.

**First implementation story:**

```bash
wp scaffold plugin semantic-posts \
  --plugin_name="SemanticPosts" \
  --plugin_description="Related posts that are actually related, without slowing your site down." \
  --plugin_author="Christinoleo" \
  --plugin_uri="https://semanticposts.com" \
  --activate=false

wp scaffold plugin-tests semantic-posts

cd wp-content/plugins/semantic-posts
composer init --name="christinoleo/semantic-posts" --type=wordpress-plugin --no-interaction
# Edit composer.json to add PSR-4 mapping: "SemanticPosts\\": "src/"
mkdir src
```

Subsequent stories follow the FR → subsystem mapping in step-06.

