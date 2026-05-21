<?php
/**
 * Free-tier indexing gate.
 *
 * Free sites can index up to FREE_POST_LIMIT posts. Beyond that, indexing
 * halts until a paid Freemius license is active. Already-indexed posts keep
 * working; new candidates simply stop entering the graph.
 *
 * The "pending" count surfaced to the admin is just `UnindexedQueue::count()`
 * when the gate is locked — no separate postmeta needed since `_sp_embedding`
 * absence is already the queue's truth.
 *
 * @package SemanticPosts\Paywall
 */

declare( strict_types=1 );

namespace SemanticPosts\Paywall;

defined( 'ABSPATH' ) || exit;

final class PaywallGate {

	public const FREE_POST_LIMIT = 200;

	/**
	 * Effective free-tier post limit. Filterable so site owners or hosting
	 * partners can raise/lower the threshold without forking.
	 */
	public function limit(): int {
		$limit = (int) apply_filters( 'semantic_posts_free_post_limit', self::FREE_POST_LIMIT );
		return $limit > 0 ? $limit : self::FREE_POST_LIMIT;
	}

	/**
	 * Whether the site currently has an active paid Freemius license. False
	 * when the SDK isn't loaded (e.g. unit tests or `SEMANTIC_POSTS_BYPASS_FREEMIUS`).
	 */
	public function is_paying(): bool {
		if ( ! function_exists( 'sp_fs' ) ) {
			return false;
		}
		$fs = sp_fs();
		if ( ! is_object( $fs ) ) {
			return false;
		}
		if ( method_exists( $fs, 'can_use_premium_code' ) && $fs->can_use_premium_code() ) {
			return true;
		}
		return method_exists( $fs, 'is_paying' ) ? (bool) $fs->is_paying() : false;
	}

	/**
	 * Whether indexing should halt at the given current count.
	 *
	 * @param int $current_indexed_count Number of posts currently in the graph.
	 */
	public function is_locked( int $current_indexed_count ): bool {
		return $current_indexed_count >= $this->limit() && ! $this->is_paying();
	}
}
