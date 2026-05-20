# Spike 0001 — Pure-PHP brute-force cosine viability on shared hosting

**Status:** Complete
**Date:** 2026-05-20
**Question:** Does the brief's architecture — pure-PHP cosine similarity over all pairs, run in WP-Cron — actually fit the CPU and memory budget of cheap shared WordPress hosting?

## Why this matters

The entire SemanticPosts positioning ("self-contained — no external vector database") depends on this working in pure PHP on a $5/mo shared host. If it doesn't, the architecture has to change (e.g., `mysql-vector` lib, MySQL 8 native vectors, or external service), and the "no external infra" pitch from the brief either weakens or collapses.

The brief asserts "pure-PHP cosine over postmeta is fine at 5k posts" but cites no measurement. This spike measures it.

## Method

Pure PHP CLI script (`spike/spike.php`), no WordPress overhead, run inside Docker with shared-host-like resource caps.

**Container:** PHP 8.1.34 CLI on Alpine, OPcache + JIT (tracing) enabled, `memory_limit=256M`.

**Constraints applied at `docker run`:** `--cpus=1 --memory=256m`. This approximates a $5/mo shared host: one CPU core, 256 MB RAM budget.

**Workload, per the brief's chosen format:**

1. Generate N random unit vectors of dim=1536 (matches OpenAI `text-embedding-3-small` output).
2. Store each as `base64(pack('f*', vec))` — the postmeta storage format from ADR-0003.
3. Decode all into `SplFixedArray<SplFixedArray<float>>` (low per-element overhead vs. regular PHP arrays — `~16` bytes/elem vs. `~80`).
4. Compute all-pairs dot products (= cosine, since vectors are pre-normalized — OpenAI embeddings already are). Maintain top-5 per row symmetrically.
5. Measure wall-clock and peak memory.

**Why dot product not cosine:** OpenAI embeddings are unit vectors, so `cosine(a, b) = a · b`. Skipping the magnitude term saves a `sqrt` and a division per pair without changing the result. Production code should do this.

**N tested:** 500, 1000, 2500, 5000. (N=10000 attempted separately — see "Open issues".)

**Reproduction:** `docs/spikes/0001-php-cosine-viability.md` is paired with `spike/` directory at the repo root:

```bash
cd spike
docker build -t semantic-spike .
docker run --rm --cpus=1 --memory=256m semantic-spike php spike.php <N>
```

## Results

### Compute and memory (JIT on, primary configuration)

| N | Compute time | Peak memory | Storage (packed) | Per-pair time |
|---|---|---|---|---|
| 500 | 6.0 s | 14 MB | 3.9 MB | 47.7 µs |
| 1000 | 23.4 s | 28 MB | 7.8 MB | 47.0 µs |
| 2500 | 148.6 s | 66 MB | 19.5 MB | 47.6 µs |
| 5000 | **586.9 s (9m47s)** | **130 MB** | **39.1 MB** | **47.0 µs** |

Per-pair time is stable at 47.0–47.7 µs across all N, confirming O(N²) extrapolation holds. The 5k-post compute time matches the extrapolation from N=500 within <2% error.

**Extrapolation to N=10000 (not measured directly):** ~2347 s (~39 min) compute, ~78 MB packed storage, ~250 MB decoded vectors — exceeds 256 MB host memory budget.

### JIT impact (N=1000)

| Configuration | Compute | Per-pair |
|---|---|---|
| OPcache + JIT enabled | 23.4 s | 47.0 µs |
| OPcache disabled (`opcache.enable_cli=0`) | 34.3 s | 68.7 µs |

JIT delta is **~1.5x**, smaller than the 2–3x guess. Implication: shared hosts that disable opcache for CLI do not catastrophically degrade the workload. Worst-case shared host stays in the same order of magnitude.

### Storage

- 8192 bytes per post (1536 floats × 4 bytes float32 = 6144 raw, +33% base64 overhead).
- N=5000 → 39 MB postmeta. **Inside the brief's `<50 MB at 5k posts` acceptance criterion.**
- The brief's "~6 KB/post" figure was raw binary; postmeta requires text-safe encoding, hence base64.

## Interpretation against brief acceptance criteria

| Brief acceptance criterion | Spike says |
|---|---|
| "Bulk index 1,000 posts completes in <2 min on $5/mo shared host" | ✅ 23 s with JIT, 34 s without — **5x margin** |
| "Bulk index never trips PHP memory limit on `WP_MEMORY_LIMIT=256M`" | ✅ at N≤5000 (130 MB peak measured). At N=10000, decode alone reaches ~250 MB — see open issues |
| "Total postmeta added at 5k posts <50 MB" | ✅ 39 MB measured |
| Compute time at 5k posts | 587 s (9m47s) — fits a batched WP-Cron loop (50 posts/batch × 100 batches × ~6 s each); does not fit a single shared-host request timeout (typically 60–120 s) |
| "Page-load cost: one `get_post_meta` call" | Not in scope of this spike — measured separately in render benchmarks |

**Bottom line so far:** the architecture works at the brief's headline number (5k posts). The "1 week part-time MVP, pure PHP, no external infra" positioning is real and defensible up to that scale.

## Open issues (surfaced by this spike)

### 1. N=10,000 hits the memory budget at decode, not compute

Decoded vector storage scales linearly: 124 MB at 5k → ~250 MB at 10k, before any compute structures. That's already at the shared-host `memory_limit=256M` ceiling, and PHP needs headroom for code, opcodes, and intermediate values.

**Implications for positioning:**
- The brief currently says "200–10,000 posts." This spike says the natural in-memory limit is ~5–6k posts on a 256 MB host.
- Three paths to handle 10k cleanly:
  - (a) **Cap positioning at 5k posts.** "For blogs up to ~5,000 posts." Covers the dominant segment, leaves veteran niche-site operators out.
  - (b) **Streaming compute.** Don't decode all rows at once — page through postmeta, holding the current outer row plus a sliding window of inner rows. Increases code complexity and adds DB round-trips.
  - (c) **Chunked compute across cron ticks with intermediate state.** Compute rows 0–1000 vs all, persist progress, next tick does 1000–2000, etc. Already implied by ADR-0002 indexing pattern; needs explicit design.

This is a design decision that should land before the PRD freezes positioning copy.

### 2. The "daily WP-Cron full recompute" model from the brief is wrong

The brief says: top-5 recomputed by daily WP-Cron. But:
- A full 5k-post recompute costs ~10 min wall-clock (this spike), well over typical shared-host PHP request timeouts (60–120 s). It has to be batched even when "daily."
- A newly published post has no related list for up to 24 hours under daily cadence — visible UX defect.

The realistic model, supported by the cost data:
- **On `save_post`:** embed the post (background, OpenAI call) and compute its row against all existing embeddings. At 5k posts × 1 row = ~0.24 s of compute. Trivial.
- **Full recompute:** only on bulk reindex or model change. Run in batches of ~50 posts per cron tick (~12 s per batch under 60 s timeout).
- **Daily cron:** not for recompute. Could be reserved for dirty-flag cleanup, retry queue, or removed entirely.

This contradicts the brief and should be resolved in an ADR before implementation. (Suggested: ADR-0004 on indexing cadence, building on ADR-0002.)

### 3. Per-pair cost has ~10x headroom if needed

47 µs per pair in pure PHP with JIT. A C extension or SIMD implementation would be ~5 µs. If a future scale target appears (20k+ posts), there is a known optimization path (PHP FFI to a C function, or the proposed `ext-vector` extension), but it adds a binary dependency that conflicts with the "works on shared hosting" pitch. Not needed for v1.

### 4. Spike does not cover

- **OpenAI API call latency and cost.** Brief estimates $0.50 for 5k posts; not validated here.
- **WordPress integration overhead.** WP hooks, autoload, query cache may add per-request overhead this spike doesn't see.
- **Suggestion quality.** Random vectors don't tell us if the math produces relevant recommendations. That's a separate blind-eval spike against a real corpus (open question #6 from planning discussion).
- **PHP versions other than 8.1.** The brief targets PHP 8.0+. 8.0 lacks the same JIT tracing maturity; could be slower. 8.2+ may be faster. Not measured.

## Recommendation

1. **Architecture is viable for v1 at the 5k-post scale.** Proceed with brief's "pure PHP, postmeta storage, brute-force cosine" plan as the v1 default.
2. **Cap positioning at "for blogs up to ~5,000 posts"** for the v1 launch, unless we implement streaming compute (option 1b above). Update brief and marketing copy.
3. **Rewrite the indexing cadence** before PRD: incremental on `save_post` for new posts, batched bulk for initial index, no daily full recompute. Capture as ADR-0004.
4. **Use dot product on pre-normalized vectors**, not full cosine formula. Document the assumption in the embedding-storage code.
5. **No JIT dependency.** The 1.5x delta is small enough that shared hosts without JIT are fine. Don't list JIT as a system requirement.

## Files

- `spike/spike.php` — measurement script
- `spike/Dockerfile` — JIT-on configuration (primary)
- `spike/Dockerfile.nojit` — JIT-off configuration (baseline)
- `spike/run.sh` — runs N=1000, 2500, 5000, 10000 sequentially under resource caps
