<?php
/**
 * Resolve which post IDs to render as "related" for a given post.
 *
 * Three sources, in priority order:
 *   1. SEMANTIC — read from `_sp_related` postmeta (populated by the Crawler).
 *   2. CATEGORY — fallback for posts that haven't been indexed yet or whose
 *      semantic list shrank below the requested count (when quality-bounded
 *      mode is off — see `semantic_posts_min_score`).
 *   3. NONE — no candidates available (e.g. uncategorised post with no embedding).
 *
 * Per-item `data_source` is also tracked: when semantic + category padding
 * mix in the same render, the template can attribute each card individually
 * via `data-sp-item-source`.
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

use SemanticPosts\Observability\Metrics;
use SemanticPosts\Observability\NullMetrics;
use WP_Post;
use WP_Query;

class SourceResolver {

	public const SOURCE_CATEGORY = 'category-fallback';
	public const SOURCE_NONE     = 'none';
	public const SOURCE_SEMANTIC = 'semantic';

	/** @var Metrics */
	private Metrics $metrics;

	/**
	 * @param Metrics|null $metrics Observability sink (TB-13). NullMetrics default for tests + back-compat.
	 */
	public function __construct( ?Metrics $metrics = null ) {
		$this->metrics = $metrics ?? new NullMetrics();
	}

	/**
	 * Resolve related items for $post.
	 *
	 * @param  WP_Post $post  Source post.
	 * @param  int     $count Requested number of items.
	 * @return array{items: int[], data_source: string, item_sources: array<int,string>}
	 */
	public function resolve( WP_Post $post, int $count = 5 ): array {
		$queries  = 0;
		$semantic = $this->semantic_related( $post, $count );
		if ( ! empty( $semantic['items'] ) ) {
			$result = $this->maybe_pad_with_category( $post, $count, $semantic, $queries );
			$this->metrics->record_render_query( $queries );
			return $result;
		}
		$result   = $this->category_fallback( $post, $count );
		$queries += empty( $result['items'] ) ? 0 : 1;
		$this->metrics->record_render_query( $queries );
		return $result;
	}

	/**
	 * Pull the Crawler-curated `_sp_related` row, applying the min-score
	 * quality filter and the multilingual defensive filter.
	 *
	 * @param  WP_Post $post  Source post.
	 * @param  int     $count Requested item count (caps the returned slice).
	 * @return array{items: int[], item_sources: array<int,string>, threshold_active: bool}
	 */
	private function semantic_related( WP_Post $post, int $count ): array {
		$raw = get_post_meta( $post->ID, '_sp_related', true );
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array(
				'items'            => array(),
				'item_sources'     => array(),
				'threshold_active' => false,
			);
		}

		// Defensive: normalise int keys + float values (postmeta unserialise can
		// retain string keys when stored under odd conditions).
		$normalized = array();
		foreach ( $raw as $id => $score ) {
			$normalized[ (int) $id ] = (float) $score;
		}

		// Quality threshold (Q1 quality-bounded mode). Default 0.0 = disabled.
		$threshold = (float) apply_filters( 'semantic_posts_min_score', 0.0, $post );
		if ( $threshold > 0.0 ) {
			$normalized = array_filter( $normalized, static fn( $score ) => $score >= $threshold );
		}

		// Preserve descending score order (Crawler already stores ordered).
		$ids = array_keys( $normalized );

		// Multilingual defensive filter.
		$ids = $this->apply_language_filter( $post->ID, $ids );

		// Cap at count.
		$ids = array_slice( $ids, 0, $count );

		return array(
			'items'            => $ids,
			'item_sources'     => array_fill_keys( $ids, self::SOURCE_SEMANTIC ),
			'threshold_active' => $threshold > 0.0,
		);
	}

	/**
	 * If the semantic list is shorter than $count AND quality-bounded mode is
	 * NOT active, pad with category-fallback items. Otherwise return the
	 * semantic list as-is (shrunk).
	 *
	 * @param  WP_Post                                                                      $post     Source post.
	 * @param  int                                                                          $count    Requested count.
	 * @param  array{items: int[], item_sources: array<int,string>, threshold_active: bool} $semantic Semantic resolution result.
	 * @param  int                                                                          $queries  Out-param — incremented for every WP_Query issued.
	 * @return array{items: int[], data_source: string, item_sources: array<int,string>}
	 */
	private function maybe_pad_with_category( WP_Post $post, int $count, array $semantic, int &$queries ): array {
		$items        = $semantic['items'];
		$item_sources = $semantic['item_sources'];

		if ( count( $items ) >= $count || $semantic['threshold_active'] ) {
			return array(
				'items'        => $items,
				'data_source'  => self::SOURCE_SEMANTIC,
				'item_sources' => $item_sources,
			);
		}

		$needed = $count - count( $items );
		// Ask the fallback for `count + needed` so we have headroom to dedupe.
		$fallback = $this->category_fallback( $post, $count + $needed );
		++$queries;
		$padding = array();
		foreach ( $fallback['items'] as $fid ) {
			if ( in_array( (int) $fid, $items, true ) ) {
				continue;
			}
			$padding[] = (int) $fid;
			if ( count( $padding ) >= $needed ) {
				break;
			}
		}

		foreach ( $padding as $pid ) {
			$item_sources[ $pid ] = self::SOURCE_CATEGORY;
		}

		return array(
			'items'        => array_merge( $items, $padding ),
			// Section-level source stays "semantic" — the mixed render has
			// semantic as the primary intent + per-item attribution.
			'data_source'  => self::SOURCE_SEMANTIC,
			'item_sources' => $item_sources,
		);
	}

	/**
	 * Category-fallback strategy via single WP_Query (TB-03).
	 *
	 * @param  WP_Post $post  Source post.
	 * @param  int     $count Requested item count.
	 * @return array{items: int[], data_source: string, item_sources: array<int,string>}
	 */
	private function category_fallback( WP_Post $post, int $count ): array {
		$category_ids = wp_get_post_categories( $post->ID );
		if ( empty( $category_ids ) ) {
			return array(
				'items'        => array(),
				'data_source'  => self::SOURCE_NONE,
				'item_sources' => array(),
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
			'items'        => $ids,
			'data_source'  => empty( $ids ) ? self::SOURCE_NONE : self::SOURCE_CATEGORY,
			'item_sources' => array_fill_keys( $ids, self::SOURCE_CATEGORY ),
		);
	}

	/**
	 * Drop candidates whose language differs from the source post's. Polylang
	 * + WPML supported; `semantic_posts_disable_language_filter` overrides.
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
	 * @param int $post_id Post ID to query.
	 */
	private function lookup_language( int $post_id ): string {
		if ( function_exists( 'pll_get_post_language' ) ) {
			return (string) pll_get_post_language( $post_id );
		}
		// WPML hook is third-party; prefix sniff doesn't apply.
		$details = apply_filters( 'wpml_post_language_details', null, $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( is_array( $details ) && isset( $details['language_code'] ) ) {
			return (string) $details['language_code'];
		}
		return '';
	}
}
