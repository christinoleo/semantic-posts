<?php
/**
 * Measure a single permalink render: TTFB, query count, HTTP calls, peak memory.
 *
 *   wp eval-file tests/Performance/Fixtures/measure-render.php [<post_id>] [--without-plugin]
 *
 * Output: JSON to stdout, one object per invocation, ready to be folded into
 * the benchmark JSON by run.sh.
 *
 * @package SemanticPosts\Tests\Performance
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WP_CLI' ) ) {
	exit;
}

$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
if ( $post_id <= 0 ) {
	$first = ( new WP_Query(
		array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		)
	) )->posts;
	$post_id = (int) ( $first[0] ?? 0 );
}

if ( $post_id <= 0 ) {
	WP_CLI::error( 'No posts available to measure.' );
}

// Count outbound HTTP via the standard escape hatch.
$http_calls = 0;
add_filter(
	'pre_http_request',
	static function ( $preempt, $args_ignored, $url ) use ( &$http_calls ) {
		unset( $args_ignored, $url );
		++$http_calls;
		// Don't actually hit the network — short-circuit with a fake response.
		return array(
			'headers'  => array(),
			'body'     => '{}',
			'response' => array( 'code' => 200, 'message' => 'OK' ),
		);
	},
	10,
	3
);

// Count queries before/after the_content().
global $wpdb;
$queries_before = is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : 0;
$mem_before     = memory_get_usage( true );
$t_start        = microtime( true );

$post = get_post( $post_id );
setup_postdata( $post );
$content = apply_filters( 'the_content', (string) $post->post_content );

$elapsed_ms     = ( microtime( true ) - $t_start ) * 1000.0;
$mem_after      = memory_get_usage( true );
$queries_after  = is_array( $wpdb->queries ?? null ) ? count( $wpdb->queries ) : 0;

WP_CLI::print_value(
	array(
		'post_id'         => $post_id,
		'ttfb_ms'         => round( $elapsed_ms, 3 ),
		'queries'         => $queries_after - $queries_before,
		'http_calls'      => $http_calls,
		'memory_added_mb' => round( ( $mem_after - $mem_before ) / 1_048_576, 3 ),
		'content_bytes'   => strlen( $content ),
	),
	array( 'format' => 'json' )
);
