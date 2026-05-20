<?php
/**
 * Manage the lifecycle of the cron tick event.
 *
 * Activation: schedule `semantic_posts_cron_tick` at the configured interval
 * (default hourly). Deactivation: clear the schedule. NO data is touched —
 * uninstall.php handles full data wipe.
 *
 * The actual cron callback (wiring TickProcessor::run) is registered by
 * Bootstrap on every request, not here. CronRegistration only handles the
 * activation-time scheduling decision + the reschedule when the user changes
 * frequency in Settings (TB-12).
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class CronRegistration {

	public const SCHEDULE_SIX_HOURS = 'semantic_posts_six_hours';

	/**
	 * Map plugin frequency slug → WP cron schedule name.
	 *
	 * @var array<string,string>
	 */
	private const SLUG_TO_SCHEDULE = array(
		'hourly'    => 'hourly',
		'six_hours' => self::SCHEDULE_SIX_HOURS,
		'daily'     => 'daily',
	);

	/**
	 * Register the custom six-hour interval with WP-Cron. Wired by Bootstrap on
	 * the `cron_schedules` filter.
	 *
	 * @param array<string,mixed> $schedules Existing schedules.
	 * @return array<string,mixed>
	 */
	public static function register_intervals( $schedules ): array {
		if ( ! is_array( $schedules ) ) {
			$schedules = array();
		}
		$schedules[ self::SCHEDULE_SIX_HOURS ] = array(
			'interval' => 6 * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours', 'semantic-posts' ),
		);
		return $schedules;
	}

	/**
	 * Schedule the tick at the given frequency. Idempotent.
	 *
	 * @param string $frequency Settings slug — hourly | six_hours | daily.
	 */
	public static function activate( string $frequency = 'hourly' ): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$schedule = self::SLUG_TO_SCHEDULE[ $frequency ] ?? 'hourly';
		if ( false === wp_next_scheduled( TickProcessor::HOOK ) ) {
			wp_schedule_event( time() + 60, $schedule, TickProcessor::HOOK );
		}
	}

	/**
	 * Re-schedule when the user changes frequency in Settings. Idempotent — a
	 * no-op when the frequency hasn't changed.
	 *
	 * @param string $frequency New frequency slug.
	 */
	public static function reschedule( string $frequency ): void {
		if ( ! function_exists( 'wp_get_scheduled_event' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		$desired = self::SLUG_TO_SCHEDULE[ $frequency ] ?? 'hourly';
		$current = wp_get_scheduled_event( TickProcessor::HOOK );
		if ( $current && isset( $current->schedule ) && $current->schedule === $desired ) {
			return; // already on the right cadence.
		}

		if ( function_exists( 'wp_unschedule_hook' ) ) {
			// Only clear the recurring tick — leave per-post immediate_embed events.
			$next = $current && isset( $current->timestamp ) ? (int) $current->timestamp : 0;
			if ( $next > 0 && function_exists( 'wp_unschedule_event' ) ) {
				wp_unschedule_event( $next, TickProcessor::HOOK );
			}
		}
		wp_schedule_event( time() + 60, $desired, TickProcessor::HOOK );
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
