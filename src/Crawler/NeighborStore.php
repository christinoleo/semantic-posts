<?php
/**
 * Single-writer owner of `_sp_related` and `_sp_inbound` postmeta (AR-10).
 *
 * This slice (TB-07) ships the read + cleanup side needed by the trash/
 * status-away path. TB-08 extends with `write_related( post_id, neighbors )`
 * and the propagation/crawler entry points.
 *
 * Storage shape:
 *   _sp_related: array<int,float> mapping neighbor post_id => score (cosine).
 *   _sp_inbound: int[] of post IDs that reference this post via their _sp_related.
 *
 * Both are stored as PHP-serialised arrays (WP's default for non-scalar postmeta).
 *
 * @package SemanticPosts\Crawler
 */

declare( strict_types=1 );

namespace SemanticPosts\Crawler;

class NeighborStore {

	public const META_RELATED = '_sp_related';
	public const META_INBOUND = '_sp_inbound';

	/**
	 * @param  int $post_id Post to read.
	 * @return array<int,float> Neighbor map post_id => score.
	 */
	public function read_related( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_sp_related', true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $k => $v ) {
			$out[ (int) $k ] = (float) $v;
		}
		return $out;
	}

	/**
	 * @param  int $post_id Post to read.
	 * @return int[] IDs of posts that reference $post_id in their _sp_related.
	 */
	public function read_inbound( int $post_id ): array {
		$raw = get_post_meta( $post_id, '_sp_inbound', true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		return array_values( array_unique( array_map( 'intval', $raw ) ) );
	}

	/**
	 * Delete both `_sp_related` and `_sp_inbound` for the given post. Called
	 * from CleanupRouter on trash / status-away.
	 *
	 * @param int $post_id Target post.
	 */
	public function delete_for_post( int $post_id ): void {
		delete_post_meta( $post_id, '_sp_related' );
		delete_post_meta( $post_id, '_sp_inbound' );
	}

	/**
	 * Remove `$dropped_id` from `$post_id`'s _sp_related (and rewrite the row).
	 * Used during cleanup: when post X is trashed, every post in X's inbound
	 * list has X removed from their related.
	 *
	 * @param int $post_id    Post whose _sp_related we mutate.
	 * @param int $dropped_id Neighbor to remove.
	 */
	public function remove_neighbor( int $post_id, int $dropped_id ): void {
		$current = $this->read_related( $post_id );
		if ( ! array_key_exists( $dropped_id, $current ) ) {
			return;
		}
		unset( $current[ $dropped_id ] );
		if ( empty( $current ) ) {
			delete_post_meta( $post_id, '_sp_related' );
		} else {
			update_post_meta( $post_id, '_sp_related', $current );
		}
	}
}
