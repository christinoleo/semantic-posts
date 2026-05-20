<?php
/**
 * Clean fixture: should pass both AR-10 and AR-12 sniffs.
 *
 * - Vector class writes `_sp_embedding` (matches owner map).
 * - AJAX callback calls `check_ajax_referer` + `current_user_can` in first 5 statements.
 */

namespace SemanticPosts\Domain;

class Vector {
	public function write( int $post_id, string $blob ): void {
		update_post_meta( $post_id, '_sp_embedding', $blob );
	}
}

add_action( 'wp_ajax_semantic_posts_run_indexing_now', 'sp_good_ajax_handler' );

function sp_good_ajax_handler(): void {
	check_ajax_referer( 'semantic_posts_admin', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
	wp_send_json_success();
}
