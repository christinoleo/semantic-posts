<?php
/**
 * Plugin uninstall — removes every persisted artifact the plugin ever wrote.
 *
 * Triggered by WordPress when the user clicks "Delete" on the Plugins screen.
 * Deactivation does NOT call this file; data is preserved across deactivate /
 * reactivate cycles intentionally (FR-12).
 *
 * Cleaned:
 *   - postmeta rows where meta_key LIKE '_sp_%'  (single DELETE; covers
 *     _sp_embedding, _sp_related, _sp_inbound, _sp_text_hash, _sp_dirty).
 *   - option `semantic_posts_settings` (the settings repo's single autoloaded option).
 *   - option `_sp_state` (the cross-tick state used by the indexing pipeline; safe
 *     to delete here even though TB-05+ writes it — uninstall implies "wipe all
 *     plugin data" regardless of which slice produced it).
 *
 * No exceptions are thrown for missing rows / options; deletion is idempotent.
 *
 * @package SemanticPosts
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Single DELETE covers every plugin-owned postmeta key. The `_sp_%` LIKE pattern
// matches the AR-10 owner map literally and survives future key additions —
// any new `_sp_*` key inherits cleanup without code change here.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( '_sp_' ) . '%'
	)
);

delete_option( 'semantic_posts_settings' );
delete_option( '_sp_state' );

// Multisite: per-site options live in each site's wp_NNN_options. Iterate when
// the plugin was network-active so we don't leave orphans on subsites.
if ( is_multisite() ) {
	$site_ids = get_sites( array( 'fields' => 'ids' ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( '_sp_' ) . '%'
			)
		);
		delete_option( 'semantic_posts_settings' );
		delete_option( '_sp_state' );
		restore_current_blog();
	}
}
