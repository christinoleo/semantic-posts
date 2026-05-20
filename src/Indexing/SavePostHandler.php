<?php
/**
 * Hooks for the post lifecycle that affect the indexing pipeline.
 *
 * Three entry points:
 *
 *   - on_save_post: short-circuits autosaves, then calls HashDiffDetector::detect.
 *     If detect flagged a change, this is the only side effect — the post is
 *     dirty and waits for the next cron tick.
 *
 *   - on_transition_post_status: when a post enters `publish` for the first time
 *     (no prior _sp_embedding), schedules an immediate single-shot EmbedJob via
 *     wp_schedule_single_event so the user sees related-posts data without
 *     waiting an hour. Status transitions AWAY from publish trigger CleanupRouter.
 *
 *   - on_trash: trash event always triggers CleanupRouter regardless of status.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use WP_Post;

class SavePostHandler {

	public const IMMEDIATE_EMBED_HOOK = 'semantic_posts_immediate_embed';

	/** @var HashDiffDetector */
	private HashDiffDetector $hash;

	/** @var CleanupRouter */
	private CleanupRouter $cleanup;

	/** @var EmbedJob */
	private EmbedJob $embed_job;

	/**
	 * @param HashDiffDetector $hash      AR-10 owner of _sp_text_hash + _sp_dirty.
	 * @param CleanupRouter    $cleanup   Trash / status-away orchestrator.
	 * @param EmbedJob         $embed_job Runs immediate single-shot embeds.
	 */
	public function __construct( HashDiffDetector $hash, CleanupRouter $cleanup, EmbedJob $embed_job ) {
		$this->hash      = $hash;
		$this->cleanup   = $cleanup;
		$this->embed_job = $embed_job;
	}

	/**
	 * `save_post` callback. Autosaves and revisions are no-ops.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( int $post_id, WP_Post $post ): void {
		if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		if ( ! empty( $post->post_password ) ) {
			// Password-protected posts are excluded from indexing.
			return;
		}

		$this->hash->detect( $post );
	}

	/**
	 * `transition_post_status` callback. Two branches:
	 *  - new publish (no prior embedding): schedule single-shot immediate embed.
	 *  - leaving publish OR gaining a password: full cleanup via CleanupRouter.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous status.
	 * @param WP_Post $post       Post object (post-transition).
	 */
	public function on_transition_post_status( string $new_status, string $old_status, WP_Post $post ): void {
		// Entering publish for the first time → immediate embed bypass.
		if ( 'publish' === $new_status && 'publish' !== $old_status ) {
			$has_embedding = (string) get_post_meta( $post->ID, '_sp_embedding', true );
			if ( '' === $has_embedding && empty( $post->post_password ) ) {
				$this->schedule_immediate_embed( $post->ID );
			}
			return;
		}

		// Leaving publish OR gaining a password → full cleanup.
		if ( 'publish' === $old_status && 'publish' !== $new_status ) {
			$this->cleanup->cleanup( $post->ID );
		}
	}

	/**
	 * `wp_trash_post` callback. Always cleans up regardless of prior status.
	 *
	 * @param int $post_id Post being trashed.
	 */
	public function on_trash( int $post_id ): void {
		$this->cleanup->cleanup( $post_id );
	}

	/**
	 * Schedule a one-shot cron event 1 second from now so the first publish
	 * lands on real cron infrastructure (not synchronous within save_post).
	 *
	 * @param int $post_id Target post.
	 */
	private function schedule_immediate_embed( int $post_id ): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}
		$args = array( $post_id );
		if ( false !== wp_next_scheduled( self::IMMEDIATE_EMBED_HOOK, $args ) ) {
			// Already queued for this post.
			return;
		}
		wp_schedule_single_event( time() + 1, self::IMMEDIATE_EMBED_HOOK, $args );
	}

	/**
	 * Cron callback for the IMMEDIATE_EMBED_HOOK single-shot event. Runs
	 * EmbedJob for a freshly-published post so the user sees related-posts
	 * data without waiting for the next hourly tick.
	 *
	 * @param int $post_id Post to embed.
	 */
	public function handle_immediate_embed( int $post_id ): void {
		$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! empty( $post->post_password ) ) {
			return;
		}
		$this->embed_job->run( $post, 1 );
	}
}
