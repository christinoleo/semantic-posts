<?php
/**
 * Weekly drift check (TB-14).
 *
 * Samples M=20 random Indexable Posts (or all of them when the corpus is
 * smaller). For each sample, computes a brute-force top-5 against the full
 * indexed corpus and compares the result to the graph's `_sp_related` via
 * Spearman footrule. The Mean Rank Displacement (MRD) — mean of per-post
 * footrule distances — is persisted in `_sp_state.verification.last_mrd`.
 *
 * Drift policy:
 *   - MRD >= threshold (default 1.5 from EV-05, override via the
 *     `semantic_posts_verification_threshold` filter): surfaces as an admin
 *     notice (rendered by VerificationNotice) suggesting "Reindex all".
 *
 * Scheduling:
 *   - `is_due()` returns true when `verification.next_due` (epoch seconds) is
 *     past now. TickProcessor calls run() opportunistically.
 *   - run() writes `last_mrd`, `last_run`, and `next_due = now + 7 days`.
 *
 * Footrule (per sample post):
 *   union = keys(graph_top_K) ∪ keys(brute_top_K)
 *   For each id in union: |pos_graph - pos_brute|  (missing ⇒ K).
 *   distance = sum / |union|.
 *
 * @package SemanticPosts\Verification
 */

declare( strict_types=1 );

namespace SemanticPosts\Verification;

use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\Vector;
use SemanticPosts\Indexing\StateRepository;
use SplFixedArray;

class VerificationPass {

	public const SAMPLE_SIZE       = 20;
	public const TOP_K             = 5;
	public const THRESHOLD_DEFAULT = 1.5;
	public const PERIOD_SECONDS    = 604800; // 7 days.

	/** @var StateRepository */
	private StateRepository $state;
	/** @var NeighborStore */
	private NeighborStore $neighbors;

	/** @var callable():int */
	private $clock;
	/** @var callable(int $size):int[] */
	private $sampler;
	/** @var callable():int[] */
	private $indexed_lister;
	/** @var callable(int $post_id):?SplFixedArray<int,float> */
	private $embedding_loader;

	/**
	 * @param StateRepository $state            State repo for verification cursor + MRD.
	 * @param NeighborStore   $neighbors        Read-side of `_sp_related`.
	 * @param callable        $clock            () => int. Unix seconds.
	 * @param callable        $sampler          (int $size) => int[]. Random sample of indexed post IDs.
	 * @param callable        $indexed_lister   () => int[]. All indexed post IDs.
	 * @param callable        $embedding_loader (int $post_id) => SplFixedArray|null.
	 */
	public function __construct(
		StateRepository $state,
		NeighborStore $neighbors,
		callable $clock,
		callable $sampler,
		callable $indexed_lister,
		callable $embedding_loader
	) {
		$this->state            = $state;
		$this->neighbors        = $neighbors;
		$this->clock            = $clock;
		$this->sampler          = $sampler;
		$this->indexed_lister   = $indexed_lister;
		$this->embedding_loader = $embedding_loader;
	}

	/**
	 * Whether the next-due timestamp has passed. TickProcessor reads this each tick.
	 */
	public function is_due(): bool {
		$state = $this->state->read();
		$next  = (int) ( $state['verification']['next_due'] ?? 0 );
		return ( $this->clock )() >= $next;
	}

	/**
	 * Run one verification pass. Returns the per-pass result and persists state.
	 *
	 * @return array{mrd:float, sampled:int, drift:bool, threshold:float}
	 */
	public function run(): array {
		$sample = ( $this->sampler )( self::SAMPLE_SIZE );
		if ( empty( $sample ) ) {
			$this->advance_cursor( 0.0 );
			return array(
				'mrd'       => 0.0,
				'sampled'   => 0,
				'drift'     => false,
				'threshold' => $this->threshold(),
			);
		}

		$distances = array();
		foreach ( $sample as $post_id ) {
			$brute       = $this->brute_top_k( (int) $post_id );
			$graph       = $this->neighbors->read_related( (int) $post_id );
			$distances[] = $this->footrule( $graph, $brute );
		}

		$mrd       = count( $distances ) > 0 ? array_sum( $distances ) / count( $distances ) : 0.0;
		$threshold = $this->threshold();

		$this->advance_cursor( $mrd );

		return array(
			'mrd'       => $mrd,
			'sampled'   => count( $sample ),
			'drift'     => $mrd >= $threshold,
			'threshold' => $threshold,
		);
	}

	/**
	 * Effective drift threshold — default 1.5, override via filter.
	 */
	public function threshold(): float {
		return (float) apply_filters( 'semantic_posts_verification_threshold', self::THRESHOLD_DEFAULT );
	}

	/**
	 * Persist last_mrd / last_run / next_due.
	 *
	 * @param float $mrd Computed MRD for the run.
	 */
	private function advance_cursor( float $mrd ): void {
		$state                             = $this->state->read();
		$now                               = ( $this->clock )();
		$state['verification']['last_mrd'] = $mrd;
		$state['verification']['last_run'] = $now;
		$state['verification']['next_due'] = $now + self::PERIOD_SECONDS;
		$this->state->write( $state );
	}

	/**
	 * Brute-force top-K against the indexed corpus by cosine similarity.
	 *
	 * @param  int $post_id Source post.
	 * @return array<int,float> id => score, descending.
	 */
	private function brute_top_k( int $post_id ): array {
		$source = ( $this->embedding_loader )( $post_id );
		if ( ! $source instanceof SplFixedArray || 0 === $source->getSize() ) {
			return array();
		}

		$scores = array();
		foreach ( ( $this->indexed_lister )() as $cid ) {
			$cid = (int) $cid;
			if ( $cid === $post_id ) {
				continue;
			}
			$vec = ( $this->embedding_loader )( $cid );
			if ( ! $vec instanceof SplFixedArray || $vec->getSize() !== $source->getSize() ) {
				continue;
			}
			$scores[ $cid ] = Vector::dot( $source, $vec );
		}
		arsort( $scores );
		return array_slice( $scores, 0, self::TOP_K, true );
	}

	/**
	 * Per-pair Spearman-footrule distance between two ranked id-lists.
	 *
	 * @param  array<int,float> $a id => score, descending.
	 * @param  array<int,float> $b id => score, descending.
	 * @return float Average per-item rank displacement.
	 */
	private function footrule( array $a, array $b ): float {
		$rank_a = array_flip( array_keys( $a ) );
		$rank_b = array_flip( array_keys( $b ) );
		$union  = array_values( array_unique( array_merge( array_keys( $a ), array_keys( $b ) ) ) );
		if ( empty( $union ) ) {
			return 0.0;
		}
		$sum = 0;
		foreach ( $union as $id ) {
			$pa   = $rank_a[ $id ] ?? self::TOP_K;
			$pb   = $rank_b[ $id ] ?? self::TOP_K;
			$sum += abs( $pa - $pb );
		}
		return $sum / count( $union );
	}
}
