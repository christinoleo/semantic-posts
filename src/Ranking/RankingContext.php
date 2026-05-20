<?php
/**
 * Lookup surface a Ranking Mode needs to score candidates without re-touching
 * post storage. Modes call back into this for embedding-on-embedding diversity
 * (DiverseMix) and post-age recency (FreshFirst).
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

use SplFixedArray;

interface RankingContext {

	/**
	 * Return the decoded embedding for $post_id, or null when not available.
	 *
	 * @param  int $post_id Post to look up.
	 * @return SplFixedArray<int,float>|null
	 */
	public function get_embedding( int $post_id ): ?SplFixedArray;

	/**
	 * Return the age in days (≥0) of $post_id since publication.
	 *
	 * @param int $post_id Post to look up.
	 */
	public function get_age_days( int $post_id ): int;
}
