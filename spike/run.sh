#!/usr/bin/env bash
# Runs the spike at increasing N under shared-host-like constraints.
# 1 CPU pinned, 256MB RAM = typical $5/mo shared host budget.
set -euo pipefail

cd "$(dirname "$0")"

docker build -t semantic-spike . >/dev/null

run_one() {
    local n="$1" mem="${2:-256m}"
    echo
    echo "════════════════════════════════════════════════════════"
    echo "  N=$n  (cpus=1, memory=$mem)"
    echo "════════════════════════════════════════════════════════"
    docker run --rm --cpus=1 --memory="$mem" semantic-spike php spike.php "$n" || \
        echo ">>> Run with N=$n failed (likely OOM or timeout). Result above."
}

run_one 1000
run_one 2500
run_one 5000
# N=10k probably needs more memory than 256M just to hold decoded vectors.
# Run with 512m to learn the compute cost even if storage doesn't fit budget.
run_one 10000 512m
