<?php
/**
 * Ranking-mode strategy interface (FR-4 / FR-9).
 *
 * The Crawler computes raw cosines for a candidate set; the active Mode
 * decides which K of those become the post's _sp_related and in what order.
 *
 * Invariant (NFR-QUAL-4): the FIRST entry returned by `rank()` MUST be the
 * highest-cosine candidate, regardless of mode. The featured-card slot is
 * a relevance promise — modes only get to re-rank items 2..K.
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

interface Mode {

	public const MOST_RELEVANT = 'most-relevant';
	public const FRESH_FIRST   = 'fresh-first';
	public const DIVERSE_MIX   = 'diverse-mix';

	/**
	 * Short stable identifier (one of the constants above).
	 */
	public function slug(): string;

	/**
	 * Pick top-K from $cosines using mode-specific scoring.
	 *
	 * @param  array<int,float> $cosines Map post_id => cosine_to_source. Caller
	 *                                   passes ALREADY-SORTED descending so the
	 *                                   featured-pinning step is trivial.
	 * @param  int              $k       Top-K cap.
	 * @param  RankingContext   $ctx     Lookup for embeddings + ages.
	 * @return array<int,float> Ordered map post_id => stored_score. Index 0 (in
	 *                          insertion order) MUST be the highest-cosine post.
	 */
	public function rank( array $cosines, int $k, RankingContext $ctx ): array;
}
