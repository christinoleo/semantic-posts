<?php
/**
 * Read-side view of Indexable Posts that don't yet have an `_sp_embedding`.
 *
 * Used by ColdStartProcessor to drain the install-time corpus into the graph.
 * Pagination is cursor-based (last_processed_id) so a killed tick resumes
 * exactly where it left off — see ADR-0008's "resumable" requirement.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Embeddings\Vector;
use WP_Query;

class UnindexedQueue {

	/**
	 * Next batch of unindexed post IDs after `$last_id`, ascending by post ID.
	 *
	 * @param  int $limit   Max IDs to return.
	 * @param  int $last_id Cursor: return only IDs greater than this.
	 * @return int[]
	 */
	public function next_batch( int $limit = 50, int $last_id = 0 ): array {
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
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => Vector::POSTMETA_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
				'date_query'             => array(),
				// Cursor pagination — IDs strictly greater than last_id.
				'post__not_in'           => array(),
			)
		);

		$ids = array_filter(
			array_map( 'intval', (array) $query->posts ),
			static fn( $id ) => $id > $last_id
		);

		return array_values( $ids );
	}

	/**
	 * Total count of unindexed Indexable Posts in the corpus. Used by the
	 * observability panel (TB-13) for cold-start progress.
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
						'key'     => Vector::POSTMETA_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		return (int) $query->found_posts;
	}
}
