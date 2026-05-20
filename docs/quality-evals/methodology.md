# Quality evaluation methodology (NFR-QUAL-1/2/3)

Manual blind comparison run before each WP.org release. Captures whether
SemanticPosts' recommendations are *judged better than the category-only
baseline* on a controlled corpus, independent of automated metrics like MRD.

## Inputs

- **Corpus.** `tests/fixtures/quality-eval-corpus-v1.json`. 100 posts across
  5 categories (20 each). Categories must be substantively different so
  cross-category recommendations are meaningful.
- **Baseline.** Same-category "latest N" — modelled after a typical
  WordPress related-posts plugin (Jetpack Related Posts / Same Category
  Posts proxy). Implemented by reusing the plugin's
  `SourceResolver::category_fallback()`.
- **Evaluator.** One human reviewer per release; future evaluators rotate
  per corpus revision to control for individual bias.

## Per-post protocol (NFR-QUAL-1)

For each of the 100 corpus posts:

1. The script renders **two top-5 lists** for the source post:
   - SP suggestions via `Crawler` walk + ranking mode = `most-relevant`.
   - Baseline via `category_fallback()` against the same post.
2. The two lists are shuffled into a single 10-card display with
   *provenance hidden* (no "SP" vs "baseline" label, no order tell). Order
   randomised per source post.
3. Evaluator answers **yes / no** on each card: "would this recommendation
   be reasonable on a real reader-facing site?"

## Aggregation + gates

| Metric | Source | Pass criterion |
| --- | --- | --- |
| Per-post reasonable count (NFR-QUAL-1) | yes-rate of the 5 SP cards | ≥ 3 of 5 in ≥ 80% of source posts |
| Corpus-wide SP vs baseline (NFR-QUAL-1) | SP yes-rate − baseline yes-rate | > 0 across the 100-post corpus |
| Cross-category share (NFR-QUAL-2, SM-C2) | fraction of SP suggestions whose category differs from source | within `[20%, 50%]` |
| Cross-domain failures (NFR-QUAL-3) | manual review of 50 additional posts with a cross-domain rubric ("completely unrelated"?) | 0 failures |

Any gate fails → release blocked. Investigate via the EV registry; tune
EV-01..EV-15 and re-run.

## Outputs

- `docs/quality-evals/{YYYY-MM-DD}.md` — per-post yes/no spreadsheet
  exported from the evaluator's UI, plus aggregate verdict.
- `docs/quality-evals/{YYYY-MM-DD}-cross-domain.md` — companion file for
  the NFR-QUAL-3 sample.

Both files committed to the repository on release.

## Re-running the eval

```bash
# Render both lists for each corpus post.
wp eval-file tools/quality-eval/render-pairs.php > /tmp/eval-pairs.json

# Open the local reviewer UI (static HTML; no backend needed).
open tools/quality-eval/review.html
```

The reviewer UI reads `/tmp/eval-pairs.json` and writes per-post answers to
local storage; export-as-CSV at the end and commit.

## Versioning

`quality-eval-corpus-v1.json` is frozen. If the corpus is substantively
revised (new categories, swapped posts) bump to `-v2.json` and note the
delta in the next per-date evaluation file.
