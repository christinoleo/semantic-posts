<?php
/**
 * Recency-weighted ranking.
 *
 * Score = cosine × exp(-age_days / decay). Decay defaults to 180 days (six
 * months) per FR-9; older posts get diminishing weight even if their
 * cosine is high. `semantic_posts_recency_decay` filter overrides.
 *
 * Featured (#1) is pinned to the highest-cosine candidate regardless of
 * recency (NFR-QUAL-4). Only items 2..K are re-ranked.
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

class FreshFirstMode implements Mode {

	public const DEFAULT_DECAY_DAYS = 180.0;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return self::FRESH_FIRST;
	}

	/**
	 * @param array<int,float> $cosines Pre-sorted descending cosines map.
	 * @param int              $k       Top-K cap.
	 * @param RankingContext   $ctx     Provides post age days.
	 * @return array<int,float>
	 */
	public function rank( array $cosines, int $k, RankingContext $ctx ): array {
		if ( empty( $cosines ) ) {
			return array();
		}

		$decay = (float) apply_filters( 'semantic_posts_recency_decay', self::DEFAULT_DECAY_DAYS );
		if ( $decay <= 0.0 ) {
			$decay = self::DEFAULT_DECAY_DAYS;
		}

		// Pin featured = highest cosine (cosines are pre-sorted desc).
		$featured_id    = array_key_first( $cosines );
		$featured_score = $cosines[ $featured_id ];

		$remaining = $cosines;
		unset( $remaining[ $featured_id ] );

		// Score remaining: cosine × exp(-age/decay).
		$blended = array();
		foreach ( $remaining as $id => $cos ) {
			$age            = max( 0, $ctx->get_age_days( $id ) );
			$blended[ $id ] = $cos * exp( -1.0 * $age / $decay );
		}
		arsort( $blended, SORT_NUMERIC );
		$rest_ordered_ids = array_slice( array_keys( $blended ), 0, $k - 1 );

		$out                 = array();
		$out[ $featured_id ] = $featured_score;
		foreach ( $rest_ordered_ids as $id ) {
			// Store the original cosine, not the blended score, so the
			// retrieved row remains comparable across mode changes.
			$out[ (int) $id ] = $remaining[ (int) $id ];
		}
		return $out;
	}
}
