<?php
/**
 * Resolve which post IDs should be rendered as "related" for a given post.
 *
 * In this slice the resolver only implements the **category-fallback** strategy:
 * the most-recent posts sharing any category with the source post. The semantic
 * (vector-based) source is wired in TB-11 by adding a branch here that reads
 * `_sp_related` postmeta when present.
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

use WP_Post;
use WP_Query;

/**
 * Pure query strategy — no template/render concerns live here.
 */
final class SourceResolver {

	public const SOURCE_CATEGORY = 'category-fallback';
	public const SOURCE_NONE     = 'none';
	public const SOURCE_SEMANTIC = 'semantic';

	/**
	 * Resolve related items for $post.
	 *
	 * @param WP_Post $post  Source post.
	 * @param int     $count Requested number of items.
	 * @return array{items: int[], data_source: string}
	 */
	public function resolve( WP_Post $post, int $count = 5 ): array {
		// TB-11 will branch on `_sp_related` postmeta here for SOURCE_SEMANTIC.
		return $this->category_fallback( $post, $count );
	}

	/**
	 * @param WP_Post $post  Source post.
	 * @param int     $count Requested number of items.
	 * @return array{items: int[], data_source: string}
	 */
	private function category_fallback( WP_Post $post, int $count ): array {
		$category_ids = wp_get_post_categories( $post->ID );
		if ( empty( $category_ids ) ) {
			return array(
				'items'       => array(),
				'data_source' => self::SOURCE_NONE,
			);
		}

		$query = new WP_Query(
			array(
				'post_type'              => $post->post_type,
				'post_status'            => 'publish',
				'post__not_in'           => array( $post->ID ),
				'category__in'           => $category_ids,
				'posts_per_page'         => max( 1, $count ),
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'ignore_sticky_posts'    => true,
			)
		);

		$ids = array_map( 'intval', (array) $query->posts );

		return array(
			'items'       => $ids,
			'data_source' => empty( $ids ) ? self::SOURCE_NONE : self::SOURCE_CATEGORY,
		);
	}
}
