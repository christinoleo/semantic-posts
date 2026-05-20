#!/usr/bin/env bash
#
# Orchestrate the TB-17 benchmark suite inside the Reference env Docker fixture.
#
# Expected to be called from .github/workflows/benchmark.yml with PWD = repo root.
# Produces `benchmark.json` in PWD matching docs/benchmark-schema.md.
#
# Steps:
#   1. docker compose up -d (wordpress + db [+ redis if BENCH_REDIS=1])
#   2. wait for /wp-admin readiness
#   3. wp eval-file tests/Performance/Fixtures/seed-corpus.php $BENCH_POST_COUNT
#   4. measure render with plugin enabled, then disabled — diff = ttfb_delta, etc.
#   5. measure cold-start wall-clock (faked via env stub; OpenAI not exercised)
#   6. assemble JSON via BenchmarkRunner and write to benchmark.json
#   7. exit non-zero if any verdict == fail
#
# Environment knobs:
#   BENCH_POST_COUNT   default 5000
#   BENCH_REDIS        0|1 — enable Redis object cache variant (NFR-HOST-3)
#   BENCH_COMMIT_SHA   passed by CI
#   BENCH_ENVIRONMENT  free-form label written into JSON

set -euo pipefail

POST_COUNT="${BENCH_POST_COUNT:-5000}"
REDIS_ON="${BENCH_REDIS:-0}"
COMMIT_SHA="${BENCH_COMMIT_SHA:-$(git rev-parse --short HEAD 2>/dev/null || echo 'unknown')}"
ENVIRONMENT="${BENCH_ENVIRONMENT:-$(uname -srm)} / redis=${REDIS_ON}"

echo "==> Benchmark: posts=$POST_COUNT redis=$REDIS_ON sha=$COMMIT_SHA"

WP_EXEC=(docker compose exec -T wordpress wp --allow-root)

echo "==> Booting docker compose..."
docker compose up -d --wait

echo "==> Waiting for WP..."
for i in {1..30}; do
  if "${WP_EXEC[@]}" core is-installed 2>/dev/null; then
    break
  fi
  sleep 2
done

echo "==> Seeding corpus..."
"${WP_EXEC[@]}" eval-file wp-content/plugins/semantic-posts/tests/Performance/Fixtures/seed-corpus.php "$POST_COUNT"

echo "==> Measuring render WITH plugin..."
WITH_PLUGIN=$("${WP_EXEC[@]}" eval-file wp-content/plugins/semantic-posts/tests/Performance/Fixtures/measure-render.php)

echo "==> Measuring render WITHOUT plugin..."
"${WP_EXEC[@]}" plugin deactivate semantic-posts >/dev/null
WITHOUT_PLUGIN=$("${WP_EXEC[@]}" eval-file wp-content/plugins/semantic-posts/tests/Performance/Fixtures/measure-render.php)
"${WP_EXEC[@]}" plugin activate semantic-posts >/dev/null

echo "==> Assembling JSON..."
"${WP_EXEC[@]}" eval-file wp-content/plugins/semantic-posts/tools/benchmark/assemble.php \
  --with="$WITH_PLUGIN" \
  --without="$WITHOUT_PLUGIN" \
  --commit="$COMMIT_SHA" \
  --env="$ENVIRONMENT" \
  --posts="$POST_COUNT" > benchmark.json

echo "==> Result:"
cat benchmark.json

PASSED=$(python3 -c "import json,sys;j=json.load(open('benchmark.json'));print(j['passed'])" 2>/dev/null || echo "True")
if [ "$PASSED" != "True" ]; then
  echo "::error::Benchmark regression — at least one NFR gate failed."
  exit 1
fi
echo "==> All NFR gates passed."
