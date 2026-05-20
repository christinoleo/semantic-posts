<?php
/**
 * Pure cosine top-K. The default ranking mode and the simplest case.
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

class MostRelevantMode implements Mode {

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return self::MOST_RELEVANT;
	}

	/**
	 * @param array<int,float> $cosines Pre-sorted descending cosines map.
	 * @param int              $k       Top-K cap.
	 * @param RankingContext   $ctx     Unused for the relevance-only mode.
	 * @return array<int,float>
	 */
	public function rank( array $cosines, int $k, RankingContext $ctx ): array {
		return array_slice( $cosines, 0, $k, true );
	}
}
