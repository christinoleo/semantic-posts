<?php
// SemanticPosts viability spike: brute-force cosine over N posts in pure PHP.
// Vectors are pre-normalized (OpenAI embeddings are unit vectors), so cosine = dot product.

declare(strict_types=1);

$N      = isset($argv[1]) ? (int)$argv[1] : 5000;
$DIM    = isset($argv[2]) ? (int)$argv[2] : 1536;
$TOP_K  = 5;
mt_srand(42);

printf("=== Spike: N=%d, dim=%d, top_k=%d ===\n", $N, $DIM, $TOP_K);
printf("PHP %s, opcache=%s, JIT=%s, memory_limit=%s\n",
    PHP_VERSION,
    function_exists('opcache_get_status') ? 'on' : 'off',
    (function_exists('opcache_get_status') && (opcache_get_status(false)['jit']['on'] ?? false)) ? 'on' : 'off',
    ini_get('memory_limit'),
);

// ---- 1. Generate N random unit vectors, store as base64(float32) per brief ----
$t = microtime(true);
$packed = new SplFixedArray($N);
for ($i = 0; $i < $N; $i++) {
    $vec = [];
    $ss = 0.0;
    for ($d = 0; $d < $DIM; $d++) {
        $v = (mt_rand() / mt_getrandmax()) * 2.0 - 1.0;
        $vec[] = $v;
        $ss += $v * $v;
    }
    $inv = 1.0 / sqrt($ss);
    for ($d = 0; $d < $DIM; $d++) $vec[$d] *= $inv;
    $packed[$i] = base64_encode(pack('f*', ...$vec));
}
$gen_time   = microtime(true) - $t;
$packed_bytes = 0;
for ($i = 0; $i < $N; $i++) $packed_bytes += strlen($packed[$i]);
printf("Generate:   %.2fs, packed storage = %.1f MB (%d bytes/post avg)\n",
    $gen_time, $packed_bytes / 1048576, intdiv($packed_bytes, $N));

// ---- 2. Decode all into SplFixedArray<SplFixedArray<float>> (compact) ----
$t = microtime(true);
$vecs = new SplFixedArray($N);
for ($i = 0; $i < $N; $i++) {
    $raw = unpack('f*', base64_decode($packed[$i]));
    $fa  = SplFixedArray::fromArray(array_values($raw), false);
    $vecs[$i] = $fa;
    $packed[$i] = null; // free the packed copy progressively
}
unset($packed);
$decode_time = microtime(true) - $t;
$mem_after_decode = memory_get_usage(true) / 1048576;
printf("Decode:     %.2fs, mem after decode = %.1f MB\n", $decode_time, $mem_after_decode);

// ---- 3. All-pairs cosine (=dot), top-K per row, symmetric pairs ----
$t = microtime(true);
$topK = new SplFixedArray($N);
for ($i = 0; $i < $N; $i++) $topK[$i] = [];

$insert = function(array &$list, float $score, int $idx) use ($TOP_K): void {
    $n = count($list);
    if ($n < $TOP_K) {
        $list[] = [$score, $idx];
        if ($n + 1 === $TOP_K) {
            usort($list, fn($a, $b) => $a[0] <=> $b[0]);
        }
        return;
    }
    if ($score > $list[0][0]) {
        $list[0] = [$score, $idx];
        usort($list, fn($a, $b) => $a[0] <=> $b[0]);
    }
};

$progress_every = max(50, intdiv($N, 20));
for ($i = 0; $i < $N; $i++) {
    $vi = $vecs[$i];
    for ($j = $i + 1; $j < $N; $j++) {
        $vj = $vecs[$j];
        $s = 0.0;
        for ($d = 0; $d < $DIM; $d++) {
            $s += $vi[$d] * $vj[$d];
        }
        $li = $topK[$i]; $insert($li, $s, $j); $topK[$i] = $li;
        $lj = $topK[$j]; $insert($lj, $s, $i); $topK[$j] = $lj;
    }
    if (($i + 1) % $progress_every === 0 || $i + 1 === $N) {
        $el = microtime(true) - $t;
        $pairs_done  = $N * ($i + 1) - ($i + 1) * ($i + 2) / 2;
        $pairs_total = $N * ($N - 1) / 2;
        $eta = $pairs_done > 0 ? $el * ($pairs_total / $pairs_done - 1) : 0.0;
        printf("  i=%d/%d  elapsed=%.1fs  ETA=%.1fs\n", $i + 1, $N, $el, $eta);
    }
}
$compute_time = microtime(true) - $t;
$peak_mb = memory_get_peak_usage(true) / 1048576;

printf("\n=== Results (N=%d) ===\n", $N);
printf("Generate:      %7.2f s\n", $gen_time);
printf("Decode:        %7.2f s\n", $decode_time);
printf("Compute:       %7.2f s\n", $compute_time);
printf("Storage:       %7.1f MB (packed)\n", $packed_bytes / 1048576);
printf("Peak memory:   %7.1f MB\n", $peak_mb);
printf("Per post:      %7.1f ms\n", $compute_time * 1000.0 / $N);
printf("Per pair:      %7.2f µs\n", $compute_time * 2e6 / ($N * ($N - 1)));

// Extrapolation
foreach ([2500, 5000, 10000] as $N2) {
    if ($N2 === $N) continue;
    $factor = ($N2 / $N) ** 2;
    printf("Extrapolate N=%d: compute ~%.0fs, storage ~%.0f MB\n",
        $N2, $compute_time * $factor, $packed_bytes / $N * $N2 / 1048576);
}
