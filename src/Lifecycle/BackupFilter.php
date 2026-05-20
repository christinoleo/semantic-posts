<?php
/**
 * Backup-exclusion filter.
 *
 * Exposes `semantic_posts_exclude_from_backup` so backup-plugin authors can ask
 * "which postmeta keys should I skip during this site's backup?" The plugin's
 * default answer is the two largest derived keys — `_sp_embedding` (~8 KB per
 * post) and `_sp_inbound` (small per row but unbounded in fanout) — which can be
 * regenerated from the source content. The remaining `_sp_*` keys stay in the
 * backup so a graceful restore leaves the user with usable state.
 *
 * @package SemanticPosts\Lifecycle
 */

declare( strict_types=1 );

namespace SemanticPosts\Lifecycle;

class BackupFilter {

	/**
	 * Default set of plugin-owned postmeta keys that backup authors may skip.
	 *
	 * @var string[]
	 */
	public const DEFAULT_EXCLUDED = array( '_sp_embedding', '_sp_inbound' );

	/**
	 * Filter callback. Merges plugin defaults with whatever the upstream caller
	 * passed in, dedup-preserving the upstream order so an integrating backup
	 * plugin can express its own list.
	 *
	 * @param  mixed $keys Upstream value (expected array, but the filter contract
	 *                     accepts anything coming from `apply_filters`).
	 * @return string[]
	 */
	public function default_excluded( $keys ): array {
		$normalized = is_array( $keys ) ? array_values( array_filter( $keys, 'is_string' ) ) : array();
		return array_values( array_unique( array_merge( $normalized, self::DEFAULT_EXCLUDED ) ) );
	}
}
