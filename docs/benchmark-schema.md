# Benchmark JSON schema

Nightly benchmark output produced by `tools/benchmark/run.sh` and assembled by
`tools/benchmark/assemble.php`. The shape is owned by
`tests/Performance/BenchmarkRunner.php` (constant `SCHEMA_VERSION`).

Breaking changes (renamed or removed keys) bump `version`. Additive changes
(new keys under `results.X`) keep the same version.

## Top-level keys

| Key | Type | Notes |
| --- | --- | --- |
| `version` | string | Schema version. Current: `"1"`. |
| `timestamp` | int | Unix seconds when the run completed. |
| `commit_sha` | string | Git SHA of the commit under test. |
| `environment` | string | Free-form label, e.g. `ubuntu-24.04 / php8.0 / mysql8.0 / redis-off`. |
| `results` | object | Per-NFR measurement dicts. |
| `verdicts` | object | Per-NFR `pass` / `fail`. |
| `passed` | bool | True iff every `verdicts.*` is `pass`. |

## `results` block

### `results["NFR-PERF-1"]` — TTFB delta vs. no-plugin baseline

| Key | Type | Notes |
| --- | --- | --- |
| `ttfb_delta_ms` | float | Single-post render TTFB with plugin minus without. Gate: ≤ 5 ms. |
| `with_plugin` | float | Raw `microtime` reading with plugin enabled. |
| `without_plugin` | float | Raw `microtime` reading with plugin deactivated. |

### `results["NFR-PERF-2"]` — Queries added per pageview

| Key | Type | Notes |
| --- | --- | --- |
| `queries_added` | int | `$wpdb->queries` count delta. Gate: ≤ 2. |
| `with_plugin` | int | |
| `without_plugin` | int | |

### `results["NFR-PERF-3"]` — Outbound HTTP during render

| Key | Type | Notes |
| --- | --- | --- |
| `http_calls` | int | Counted via `pre_http_request` filter. Gate: must be `0`. |

### `results["NFR-PERF-4"]` — Peak memory added

| Key | Type | Notes |
| --- | --- | --- |
| `memory_added_mb` | float | `memory_get_usage(true)` delta. Gate: ≤ 1.0 MB. |
| `with_plugin` | float | |
| `without_plugin` | float | |

### `results["NFR-IDX-1"]` — Cold-start wall clock for 5k posts

| Key | Type | Notes |
| --- | --- | --- |
| `cold_start_ms` | int | `_sp_state.cold_start.completed − started`, in ms. Gate: ≤ 180 000 (3 min). |
| `post_count` | int | Corpus size used in the run. |

## `verdicts` block

```json
"verdicts": {
  "NFR-PERF-1": "pass",
  "NFR-PERF-2": "pass",
  "NFR-PERF-3": "pass",
  "NFR-PERF-4": "pass",
  "NFR-IDX-1":  "pass"
}
```

Verdict values are `"pass"` or `"fail"`. The job fails (non-zero exit) when
any verdict is `fail`.

## Example payload

```json
{
  "version": "1",
  "timestamp": 1714579501,
  "commit_sha": "c7e0d1f",
  "environment": "ubuntu-24.04 / php8.0 / mysql8.0 / default",
  "results": {
    "NFR-PERF-1": { "ttfb_delta_ms": 2.41, "with_plugin": 18.7, "without_plugin": 16.3 },
    "NFR-PERF-2": { "queries_added": 1, "with_plugin": 19, "without_plugin": 18 },
    "NFR-PERF-3": { "http_calls": 0 },
    "NFR-PERF-4": { "memory_added_mb": 0.4, "with_plugin": 11.8, "without_plugin": 11.4 },
    "NFR-IDX-1":  { "cold_start_ms": 165000, "post_count": 5000 }
  },
  "verdicts": {
    "NFR-PERF-1": "pass",
    "NFR-PERF-2": "pass",
    "NFR-PERF-3": "pass",
    "NFR-PERF-4": "pass",
    "NFR-IDX-1":  "pass"
  },
  "passed": true
}
```

## Variants

The nightly workflow runs the matrix `{default, redis}`. The `redis` variant
boots an additional `redis` service via `docker-compose.override.redis.yml`
and installs `wp-redis` as a drop-in object cache.

Cross-variant comparison is performed offline; the workflow only fails on
absolute-threshold regressions, not on delta-between-variants regressions.

## Updating the schema

1. Modify `BenchmarkRunner` (add fields, bump `SCHEMA_VERSION` when breaking).
2. Update `BenchmarkRunnerTest` to pin the new shape.
3. Update this file to match.
4. Mention the bump in the PR description so downstream dashboards know to
   adapt before the next nightly run.
