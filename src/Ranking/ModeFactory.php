<?php
/**
 * Map a mode slug to a Mode instance. Lives separately so the Crawler doesn't
 * `new MostRelevantMode` directly (cheaper test seam + future Pro modes).
 *
 * @package SemanticPosts\Ranking
 */

declare( strict_types=1 );

namespace SemanticPosts\Ranking;

class ModeFactory {

	/**
	 * Resolve a slug to a Mode instance. Unknown / invalid slugs fall back
	 * to MostRelevant — a safe, deterministic default.
	 *
	 * @param string $slug Settings value (Mode::MOST_RELEVANT etc).
	 */
	public function make( string $slug ): Mode {
		switch ( $slug ) {
			case Mode::FRESH_FIRST:
				return new FreshFirstMode();
			case Mode::DIVERSE_MIX:
				return new DiverseMixMode();
			case Mode::MOST_RELEVANT:
			default:
				return new MostRelevantMode();
		}
	}
}
