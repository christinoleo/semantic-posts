<?php
/**
 * Manage the lifecycle of the hourly cron event.
 *
 * Activation: schedule `semantic_posts_cron_tick` hourly.
 * Deactivation: clear the schedule. NO data is touched — uninstall.php handles
 * full data wipe.
 *
 * The actual cron callback (wiring TickProcessor::run) is registered by
 * Bootstrap on every request, not here. CronRegistration only handles the
 * activation-time scheduling decision.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class CronRegistration {

	/**
	 * Schedule the hourly tick. Idempotent — `wp_next_scheduled` short-circuits
	 * re-activation.
	 */
	public static function activate(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( false === wp_next_scheduled( TickProcessor::HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', TickProcessor::HOOK );
		}
	}

	/**
	 * Unschedule. Idempotent.
	 *
	 * Uses `wp_unschedule_hook` so per-post `immediate_embed` events (whose
	 * args differ per post) are cleared along with the recurring tick.
	 * `wp_clear_scheduled_hook($hook)` only matches events with empty args.
	 */
	public static function deactivate(): void {
		if ( function_exists( 'wp_unschedule_hook' ) ) {
			wp_unschedule_hook( TickProcessor::HOOK );
			wp_unschedule_hook( SavePostHandler::IMMEDIATE_EMBED_HOOK );
			return;
		}
		// Pre-WP-4.9.9 fallback.
		if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
			wp_clear_scheduled_hook( TickProcessor::HOOK );
			wp_clear_scheduled_hook( SavePostHandler::IMMEDIATE_EMBED_HOOK );
		}
	}
}
