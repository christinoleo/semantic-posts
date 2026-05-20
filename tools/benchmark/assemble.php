<?php
/**
 * Fold the per-render JSON blobs into the canonical benchmark.json
 * (docs/benchmark-schema.md).
 *
 *   wp eval-file tools/benchmark/assemble.php \
 *     --with=<json>  --without=<json>  --commit=<sha>  --env=<label>  --posts=<int>
 *
 * @package SemanticPosts\Tests\Performance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	exit;
}

require_once WP_PLUGIN_DIR . '/semantic-posts/tests/Performance/BenchmarkRunner.php';

use SemanticPosts\Tests\Performance\BenchmarkRunner;

$flags = WP_CLI\Utils\get_flag_value( $assoc_args, '', '' );

$with    = isset( $assoc_args['with'] ) ? json_decode( (string) $assoc_args['with'], true ) : array();
$without = isset( $assoc_args['without'] ) ? json_decode( (string) $assoc_args['without'], true ) : array();
$commit  = (string) ( $assoc_args['commit'] ?? '' );
$env     = (string) ( $assoc_args['env'] ?? '' );
$posts   = (int) ( $assoc_args['posts'] ?? 0 );

if ( ! is_array( $with ) || ! is_array( $without ) ) {
	WP_CLI::error( 'Invalid measurement payload.' );
}

$ttfb_with    = (float) ( $with['ttfb_ms'] ?? 0 );
$ttfb_without = (float) ( $without['ttfb_ms'] ?? 0 );
$queries_with    = (int) ( $with['queries'] ?? 0 );
$queries_without = (int) ( $without['queries'] ?? 0 );
$memory_with    = (float) ( $with['memory_added_mb'] ?? 0 );
$memory_without = (float) ( $without['memory_added_mb'] ?? 0 );

$cold_start_ms = (int) ( get_option( '_sp_state' )['cold_start']['completed'] ?? 0 )
	- (int) ( get_option( '_sp_state' )['cold_start']['started'] ?? 0 );
$cold_start_ms = max( 0, $cold_start_ms * 1000 );

$runner = new BenchmarkRunner(
	array(
		'NFR-PERF-1' => static function () use ( $ttfb_with, $ttfb_without ) {
			return array(
				'ttfb_delta_ms' => round( max( 0.0, $ttfb_with - $ttfb_without ), 3 ),
				'with_plugin'   => $ttfb_with,
				'without_plugin' => $ttfb_without,
			);
		},
		'NFR-PERF-2' => static function () use ( $queries_with, $queries_without ) {
			return array(
				'queries_added' => max( 0, $queries_with - $queries_without ),
				'with_plugin'   => $queries_with,
				'without_plugin' => $queries_without,
			);
		},
		'NFR-PERF-3' => static function () use ( $with ) {
			return array(
				'http_calls' => (int) ( $with['http_calls'] ?? 0 ),
			);
		},
		'NFR-PERF-4' => static function () use ( $memory_with, $memory_without ) {
			return array(
				'memory_added_mb' => round( max( 0.0, $memory_with - $memory_without ), 3 ),
				'with_plugin'     => $memory_with,
				'without_plugin'  => $memory_without,
			);
		},
		'NFR-IDX-1' => static function () use ( $cold_start_ms, $posts ) {
			return array(
				'cold_start_ms' => $cold_start_ms,
				'post_count'    => $posts,
			);
		},
	),
	static fn(): int => time(),
	$env,
	$commit
);

$payload = $runner->run();
echo BenchmarkRunner::to_json( $payload, true );
