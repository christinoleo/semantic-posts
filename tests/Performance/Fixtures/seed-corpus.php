<?php
/**
 * Seed a 5,000-post benchmark corpus into the current WP install.
 *
 * Designed to be invoked via WP-CLI inside the Reference env Docker fixture:
 *
 *   wp eval-file tests/Performance/Fixtures/seed-corpus.php [<count>]
 *
 * Each post receives a deterministic random 1536-dim float32 embedding so the
 * render path has _sp_related rows to work against without burning OpenAI
 * credits. Topical structure is faked: every batch of ~50 posts shares a
 * cluster centroid, then jitter is added per-post.
 *
 * Idempotent: re-runs short-circuit when the requested count is already
 * present.
 *
 * @package SemanticPosts\Tests\Performance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	exit;
}

use SemanticPosts\Embeddings\Vector;

$count = isset( $args[0] ) ? (int) $args[0] : 5000;

$existing = (int) ( new WP_Query(
	array(
		'post_type'              => 'post',
		'post_status'            => 'publish',
		'posts_per_page'         => 1,
		'no_found_rows'          => false,
		'fields'                 => 'ids',
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	)
) )->found_posts;

if ( $existing >= $count ) {
	WP_CLI::success( sprintf( 'Corpus already has %d posts (>= target %d). Skipping seed.', $existing, $count ) );
	return;
}

WP_CLI::log( sprintf( 'Seeding %d posts (have %d).', $count - $existing, $existing ) );

mt_srand( 42 ); // Deterministic.

$cluster_count    = 50;
$cluster_centroids = array();
for ( $c = 0; $c < $cluster_count; $c++ ) {
	$centroid = array();
	for ( $i = 0; $i < 1536; $i++ ) {
		$centroid[] = mt_rand( -100, 100 ) / 100.0;
	}
	$cluster_centroids[] = sp_bench_normalize( $centroid );
}

$to_create = $count - $existing;
$progress  = WP_CLI\Utils\make_progress_bar( 'Seeding posts', $to_create );

for ( $n = 0; $n < $to_create; $n++ ) {
	$cluster_id = $n % $cluster_count;
	$centroid   = $cluster_centroids[ $cluster_id ];

	$vec = array();
	for ( $i = 0; $i < 1536; $i++ ) {
		$jitter = mt_rand( -10, 10 ) / 1000.0;
		$vec[]  = $centroid[ $i ] + $jitter;
	}
	$vec = sp_bench_normalize( $vec );

	$id = wp_insert_post(
		array(
			'post_title'   => sprintf( 'Benchmark post %05d (cluster %d)', $n + 1, $cluster_id ),
			'post_content' => str_repeat( "Cluster {$cluster_id} body line.\n\n", 8 ),
			'post_status'  => 'publish',
			'post_author'  => 1,
		)
	);
	if ( is_wp_error( $id ) ) {
		continue;
	}

	Vector::write_embedding( (int) $id, $vec );
	// Hash + dirty bits are owned by HashDiffDetector (AR-10). The benchmark
	// only exercises the render path, which never reads them — so we skip
	// writing them here on purpose.

	$progress->tick();
}

$progress->finish();
WP_CLI::success( 'Corpus seeded.' );

/**
 * L2-normalize a vector so cosine = dot.
 *
 * @param float[] $vec Vector to normalize.
 * @return float[]
 */
function sp_bench_normalize( array $vec ): array {
	$sum_sq = 0.0;
	foreach ( $vec as $v ) {
		$sum_sq += $v * $v;
	}
	$norm = sqrt( $sum_sq ) ?: 1.0;
	foreach ( $vec as $i => $v ) {
		$vec[ $i ] = $v / $norm;
	}
	return $vec;
}
