<?php
/**
 * AR-10 fixture: writes an unknown `_sp_*` postmeta key not in the owner map.
 *
 * Expected: SemanticPosts.PostMeta.SingleWriter.UnknownKey
 */

namespace SemanticPosts\Domain;

class Vector {
	public function rogue_write( int $post_id ): void {
		update_post_meta( $post_id, '_sp_undocumented_key', 'oops' );
	}
}
