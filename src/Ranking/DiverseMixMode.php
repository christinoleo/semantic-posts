<?php
/**
 * Maximal Marginal Relevance (MMR) ranking.
 *
 * Item 1: highest cosine to source (per NFR-QUAL-4).
 * Items 2..K: iteratively pick the candidate that maximises
 *   λ × cosine(source, candidate) − (1 − λ) × max_cosine(candidate, already_picked)
 *
 * λ defaults to 0.7 — relevance-biased; `semantic_posts_mmr_lambda` filter
 * overrides. λ=1.0 collapses to MostRelevant; λ=0.0 collapses to pure novelty.
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

use SemanticPosts\Embeddings\Vector;

class DiverseMixMode implements Mode {

	public const DEFAULT_LAMBDA = 0.7;

	/**
	 * @inheritDoc
	 */
	public function slug(): string {
		return self::DIVERSE_MIX;
	}

	/**
	 * @param array<int,float> $cosines Pre-sorted descending cosines map.
	 * @param int              $k       Top-K cap.
	 * @param RankingContext   $ctx     Provides candidate embeddings for diversity check.
	 * @return array<int,float>
	 */
	public function rank( array $cosines, int $k, RankingContext $ctx ): array {
		if ( empty( $cosines ) ) {
			return array();
		}

		$lambda = (float) apply_filters( 'semantic_posts_mmr_lambda', self::DEFAULT_LAMBDA );
		if ( $lambda < 0.0 ) {
			$lambda = 0.0;
		}
		if ( $lambda > 1.0 ) {
			$lambda = 1.0;
		}

		// Pin featured first.
		$featured_id = array_key_first( $cosines );
		$picked      = array( $featured_id => $cosines[ $featured_id ] );
		$remaining   = $cosines;
		unset( $remaining[ $featured_id ] );

		$picked_count = count( $picked );
		while ( $picked_count < $k && ! empty( $remaining ) ) {
			$best_id    = null;
			$best_score = -INF;

			foreach ( $remaining as $cand_id => $cand_cos ) {
				$cand_emb          = $ctx->get_embedding( $cand_id );
				$max_sim_to_picked = 0.0;
				if ( null !== $cand_emb ) {
					foreach ( array_keys( $picked ) as $picked_id ) {
						$picked_emb = $ctx->get_embedding( $picked_id );
						if ( null === $picked_emb || $picked_emb->getSize() !== $cand_emb->getSize() ) {
							continue;
						}
						$sim = Vector::dot( $cand_emb, $picked_emb );
						if ( $sim > $max_sim_to_picked ) {
							$max_sim_to_picked = $sim;
						}
					}
				}
				$mmr = ( $lambda * $cand_cos ) - ( ( 1.0 - $lambda ) * $max_sim_to_picked );
				if ( $mmr > $best_score ) {
					$best_score = $mmr;
					$best_id    = $cand_id;
				}
			}

			if ( null === $best_id ) {
				break;
			}

			$picked[ $best_id ] = $remaining[ $best_id ];
			unset( $remaining[ $best_id ] );
			++$picked_count;
		}

		return $picked;
	}
}
