<?php
/**
 * Read-side view of posts that need (re-)embedding.
 *
 * Backed by a `meta_query` on `_sp_dirty=1`. Returning only IDs keeps the
 * tick's memory usage flat — TickProcessor fetches each WP_Post on demand
 * via `get_post()` so PHP doesn't pre-load every dirty post into memory.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use WP_Query;

class DirtyQueue {

	/**
	 * @param  int $limit Max IDs to return.
	 * @return int[]
	 */
	public function next_batch( int $limit = 50 ): array {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => max( 1, $limit ),
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _sp_dirty is the natural index for the dirty queue.
					array(
						'key'     => '_sp_dirty',
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);

		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Count dirty posts — used by StateRepository to surface in the
	 * observability panel (TB-13).
	 */
	public function count(): int {
		$query = new WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => false,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_sp_dirty',
						'value'   => '1',
						'compare' => '=',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}
}
