<?php
/**
 * Warm-path crawler — incrementally maintains the Similarity Graph
 * (ADR-0004).
 *
 * `update( $post_id )` runs immediately after EmbedJob writes a new embedding
 * for a post. It refreshes that post's own `_sp_related` against a tightly
 * bounded candidate set, then propagates the change to neighbors whose top-K
 * may have shifted.
 *
 * Candidate set per ADR-0004:
 *   old `_sp_related`  ∪  neighbors-of-those  ∪  `_sp_inbound`  ∪  10 random
 *
 * Resulting compute is O(K²) per save independent of corpus size — the
 * insertion-time (cold-start) algorithm lives in TB-09 (ADR-0008).
 *
 * Insertion mode for posts with no prior `_sp_embedding` falls back to the
 * "random 10" branch only; TB-09 supersedes this for proper bootstrap.
 *
 * Multilingual defensive filter: when Polylang/WPML is active, candidates are
 * restricted to the source post's language. Override with the
 * `semantic_posts_disable_language_filter` filter.
 *
 * @package SemanticPosts\Crawler
 */

declare( strict_types=1 );

namespace SemanticPosts\Crawler;

use SemanticPosts\Embeddings\Vector;
use SplFixedArray;

class Crawler {

	/**
	 * Outbound K per post (FR-4 / ADR-0004 default).
	 */
	public const TOP_K = 5;

	/**
	 * Random sample size used to widen the candidate set beyond local
	 * neighborhood (so isolated subgraphs eventually connect).
	 */
	public const RANDOM_SAMPLE = 10;

	/**
	 * Total compute budget across one update() call — cosine ops above this
	 * count get an `info` log line so retros can spot pathological corpora.
	 */
	public const SOFT_BUDGET = 200;

	/** @var NeighborStore */
	private NeighborStore $neighbors;

	/** @var callable():int[] */
	private $random_sampler;

	/**
	 * @param NeighborStore         $neighbors      Graph storage.
	 * @param callable():int[]|null $random_sampler Override the random-sample source (test seam).
	 */
	public function __construct( NeighborStore $neighbors, ?callable $random_sampler = null ) {
		$this->neighbors      = $neighbors;
		$this->random_sampler = $random_sampler ?? function (): array {
			return $this->default_random_sample();
		};
	}

	/**
	 * Refresh `_sp_related` for `$post_id` (the source) and propagate to
	 * affected neighbors.
	 *
	 * Returns the number of cosine operations performed for telemetry / tests.
	 *
	 * @param int $post_id Source post.
	 */
	public function update( int $post_id ): int {
		$source_emb = $this->load_embedding( $post_id );
		if ( null === $source_emb ) {
			return 0;
		}

		$old_outbound = $this->neighbors->read_related( $post_id );
		$old_inbound  = $this->neighbors->read_inbound( $post_id );

		// Build candidate set: own outbound + neighbors-of-those + inbound + random.
		$candidates = array();

		foreach ( array_keys( $old_outbound ) as $nid ) {
			$candidates[ $nid ] = true;
			foreach ( array_keys( $this->neighbors->read_related( $nid ) ) as $non ) {
				$candidates[ $non ] = true;
			}
		}
		foreach ( $old_inbound as $in_id ) {
			$candidates[ $in_id ] = true;
		}
		foreach ( ( $this->random_sampler )() as $rid ) {
			$candidates[ (int) $rid ] = true;
		}

		// Exclude self.
		unset( $candidates[ $post_id ] );

		// Defensive multilingual filter — drop candidates in a different language.
		$candidates = $this->apply_language_filter( $post_id, array_keys( $candidates ) );

		// Score candidates by cosine.
		$ops    = 0;
		$scored = array();
		foreach ( $candidates as $cand_id ) {
			$cand_emb = $this->load_embedding( $cand_id );
			if ( null === $cand_emb ) {
				continue;
			}
			if ( $cand_emb->getSize() !== $source_emb->getSize() ) {
				continue;
			}
			$scored[ $cand_id ] = Vector::dot( $source_emb, $cand_emb );
			++$ops;
		}

		arsort( $scored, SORT_NUMERIC );
		$top_k = array_slice( $scored, 0, self::TOP_K, true );

		// Persist new outbound for source.
		$this->neighbors->write_related( $post_id, $top_k );

		// Update inbound mirrors: anyone who gained/lost source as outbound.
		$old_outbound_ids = array_keys( $old_outbound );
		$new_outbound_ids = array_keys( $top_k );

		foreach ( array_diff( $new_outbound_ids, $old_outbound_ids ) as $gained ) {
			$this->neighbors->add_inbound( (int) $gained, $post_id );
		}
		foreach ( array_diff( $old_outbound_ids, $new_outbound_ids ) as $lost ) {
			$this->neighbors->remove_inbound( (int) $lost, $post_id );
		}

		// Propagation: for every neighbor whose top-K may be affected, recompute
		// just THAT neighbor against the source. We don't fully re-walk neighbors
		// (that would explode the cost graph) — we just check whether source
		// belongs in their top-K with its new score.
		$propagation_set = array_unique( array_merge( $old_outbound_ids, $new_outbound_ids, $old_inbound ) );
		foreach ( $propagation_set as $neighbor_id ) {
			if ( (int) $neighbor_id === $post_id ) {
				continue;
			}
			$neighbor_emb = $this->load_embedding( (int) $neighbor_id );
			if ( null === $neighbor_emb || $neighbor_emb->getSize() !== $source_emb->getSize() ) {
				continue;
			}
			$score = Vector::dot( $source_emb, $neighbor_emb );
			++$ops;

			$neighbor_related = $this->neighbors->read_related( (int) $neighbor_id );
			$this->maybe_insert_into_top_k( (int) $neighbor_id, $post_id, $score, $neighbor_related );
		}

		return $ops;
	}

	/**
	 * Decide whether `$candidate_id` (with new score) should enter `$post_id`'s
	 * top-K. If yes, write the updated row + inbound mirror; if no, ensure the
	 * candidate is not lingering.
	 *
	 * @param int              $post_id        Post whose row we may mutate.
	 * @param int              $candidate_id   Candidate (typically the source post that just got re-embedded).
	 * @param float            $score          Newly-computed cosine.
	 * @param array<int,float> $current_top_k  Current row before this update.
	 */
	private function maybe_insert_into_top_k( int $post_id, int $candidate_id, float $score, array $current_top_k ): void {
		// If we already have K entries and candidate beats the worst, swap; else if
		// candidate is currently present but its new score is lower than another
		// non-present candidate, we still update the score. Keeping things simple:
		// always update score if present, then re-trim to top-K.
		$current_top_k[ $candidate_id ] = $score;
		arsort( $current_top_k, SORT_NUMERIC );
		$new = array_slice( $current_top_k, 0, self::TOP_K, true );

		if ( $new === $this->neighbors->read_related( $post_id ) ) {
			return;
		}

		$prev_ids = array_keys( $current_top_k );
		$new_ids  = array_keys( $new );

		// If candidate fell OUT of top-K, remove inbound mirror.
		if ( ! in_array( $candidate_id, $new_ids, true ) && in_array( $candidate_id, $prev_ids, true ) ) {
			$this->neighbors->remove_inbound( $candidate_id, $post_id );
		} elseif ( in_array( $candidate_id, $new_ids, true ) ) {
			$this->neighbors->add_inbound( $candidate_id, $post_id );
		}

		// Update inbound mirrors for any IDs that fell out of the top-K rewrite.
		$dropped = array_diff( $prev_ids, $new_ids );
		foreach ( $dropped as $drop_id ) {
			if ( (int) $drop_id === $candidate_id ) {
				// Already handled above.
				continue;
			}
			$this->neighbors->remove_inbound( (int) $drop_id, $post_id );
		}

		$this->neighbors->write_related( $post_id, $new );
	}

	/**
	 * Read + decode an embedding postmeta entry to an SplFixedArray.
	 *
	 * @param  int $post_id Source post.
	 * @return SplFixedArray<int,float>|null Null when missing/invalid.
	 */
	private function load_embedding( int $post_id ): ?SplFixedArray {
		$raw = (string) get_post_meta( $post_id, Vector::POSTMETA_KEY, true );
		if ( '' === $raw ) {
			return null;
		}
		$decoded = Vector::decode( $raw );
		if ( 0 === $decoded->getSize() ) {
			return null;
		}
		return $decoded;
	}

	/**
	 * Default random sampler — picks up to RANDOM_SAMPLE posts that already
	 * have embeddings (so we don't waste cosine ops on un-indexed posts).
	 *
	 * Uses a single WP_Query with meta_query + orderby=rand.
	 *
	 * @return int[]
	 */
	private function default_random_sample(): array {
		if ( ! class_exists( '\\WP_Query' ) ) {
			return array();
		}
		$query = new \WP_Query(
			array(
				'post_type'              => 'any',
				'post_status'            => 'publish',
				'has_password'           => false,
				'posts_per_page'         => self::RANDOM_SAMPLE,
				'fields'                 => 'ids',
				'orderby'                => 'rand',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Vector::POSTMETA_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Drop candidates whose language differs from the source post's. Polylang
	 * and WPML expose `pll_get_post_language` / `wpml_post_language_details`
	 * respectively; if neither is active OR the filter disables this guard,
	 * candidates pass through unchanged.
	 *
	 * @param  int   $post_id    Source post.
	 * @param  int[] $candidates Candidate post IDs.
	 * @return int[]
	 */
	private function apply_language_filter( int $post_id, array $candidates ): array {
		if ( apply_filters( 'semantic_posts_disable_language_filter', false, $post_id ) ) {
			return array_map( 'intval', $candidates );
		}

		$source_lang = $this->lookup_language( $post_id );
		if ( '' === $source_lang ) {
			return array_map( 'intval', $candidates );
		}

		$filtered = array();
		foreach ( $candidates as $cid ) {
			$cid_lang = $this->lookup_language( (int) $cid );
			if ( '' === $cid_lang || $cid_lang === $source_lang ) {
				$filtered[] = (int) $cid;
			}
		}
		return $filtered;
	}

	/**
	 * @param int $post_id Post to look up.
	 */
	private function lookup_language( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			$lang = (string) pll_get_post_language( $post_id );
			return $lang;
		}
		// WPML's hook is third-party; the prefix sniff doesn't apply.
		if ( function_exists( 'apply_filters' ) ) {
			$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			if ( is_array( $details ) && isset( $details['language_code'] ) ) {
				return (string) $details['language_code'];
			}
		}
		return '';
	}
}
