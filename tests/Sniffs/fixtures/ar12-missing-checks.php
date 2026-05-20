<?php
/**
 * AR-12 fixture: registers a `wp_ajax_*` callback that does NOT call
 * `check_ajax_referer` or `current_user_can` in its first 5 statements.
 *
 * Expected: SemanticPosts.Ajax.NonceCapBoundary.MissingBoundaryCheck
 */

add_action( 'wp_ajax_semantic_posts_evil', 'sp_evil_ajax_handler' );

function sp_evil_ajax_handler(): void {
	$post_id = isset( $_POST['post_id'] ) ? (int) $_POST['post_id'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
	update_post_meta( $post_id, 'rogue_meta', 'no checks here' );
	wp_send_json_success();
}
