<?php
/**
 * Wipe all plugin-owned postmeta.
 *
 * Used by the wipe-and-reindex flow (model change in admin) — distinct from
 * uninstall.php which also drops settings and `_sp_state`. The Wiper preserves
 * settings (so the user's API key + display config survives) and just resets
 * the cold-start cursor so a subsequent ColdStartProcessor::start() re-indexes
 * everything fresh under the new model.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class Wiper {

	private const META_PATTERN = '_sp_';

	/** @var StateRepository */
	private StateRepository $state;

	/**
	 * @param StateRepository $state State repository so the cold-start cursor can
	 *                               be reset after the wipe.
	 */
	public function __construct( StateRepository $state ) {
		$this->state = $state;
	}

	/**
	 * Delete every `_sp_*` postmeta row and reset the cold-start cursor.
	 * Returns the number of postmeta rows deleted.
	 *
	 * Does NOT touch settings, the API key, or metrics. Caller is expected to
	 * trigger ColdStartProcessor::start() afterwards if they want immediate
	 * re-indexing.
	 */
	public function wipe_embeddings(): int {
		global $wpdb;

		$rows = 0;
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$like = method_exists( $wpdb, 'esc_like' )
				? $wpdb->esc_like( self::META_PATTERN ) . '%'
				: self::META_PATTERN . '%';
			$rows = (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
				$wpdb->prepare(
					"DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s",
					$like
				)
			);
		}

		$state                                    = $this->state->read();
		$state['cold_start']                      = array();
		$state['cold_start']['phase']             = ColdStartProcessor::PHASE_IDLE;
		$state['cold_start']['last_processed_id'] = 0;
		$this->state->write( $state );

		return $rows;
	}
}
