<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\CronRegistration;

final class CronRegistrationTest extends TestCase {

	public static array $scheduled         = array();
	public static array $unscheduled       = array();
	public static ?object $current_event   = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		self::$scheduled     = array();
		self::$unscheduled   = array();
		self::$current_event = null;
		if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
			define( 'HOUR_IN_SECONDS', 3600 );
		}

		Functions\when( 'wp_next_scheduled' )->alias(
			static fn( $hook ) => self::$current_event ? (int) self::$current_event->timestamp : false
		);
		Functions\when( 'wp_get_scheduled_event' )->alias(
			static fn( $hook ) => self::$current_event
		);
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $ts, $schedule, $hook ) {
				self::$scheduled[] = array( (int) $ts, (string) $schedule, (string) $hook );
				self::$current_event = (object) array(
					'timestamp' => (int) $ts,
					'schedule'  => (string) $schedule,
					'hook'      => (string) $hook,
				);
				return true;
			}
		);
		Functions\when( 'wp_unschedule_event' )->alias(
			static function ( $ts, $hook ) {
				self::$unscheduled[] = array( (int) $ts, (string) $hook );
				self::$current_event = null;
				return true;
			}
		);
		Functions\when( 'wp_unschedule_hook' )->justReturn( true );
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_register_intervals_adds_six_hours_schedule(): void {
		$schedules = CronRegistration::register_intervals( array() );
		$this->assertArrayHasKey( CronRegistration::SCHEDULE_SIX_HOURS, $schedules );
		$this->assertSame( 6 * HOUR_IN_SECONDS, $schedules[ CronRegistration::SCHEDULE_SIX_HOURS ]['interval'] );
	}

	public function test_register_intervals_preserves_existing_schedules(): void {
		$schedules = CronRegistration::register_intervals( array( 'weekly' => array( 'interval' => 604800 ) ) );
		$this->assertArrayHasKey( 'weekly', $schedules );
		$this->assertArrayHasKey( CronRegistration::SCHEDULE_SIX_HOURS, $schedules );
	}

	public function test_register_intervals_tolerates_non_array_input(): void {
		$schedules = CronRegistration::register_intervals( 'broken' );
		$this->assertIsArray( $schedules );
		$this->assertArrayHasKey( CronRegistration::SCHEDULE_SIX_HOURS, $schedules );
	}

	public function test_activate_uses_hourly_by_default(): void {
		CronRegistration::activate();
		$this->assertCount( 1, self::$scheduled );
		$this->assertSame( 'hourly', self::$scheduled[0][1] );
	}

	public function test_activate_with_daily_uses_daily_schedule(): void {
		CronRegistration::activate( 'daily' );
		$this->assertSame( 'daily', self::$scheduled[0][1] );
	}

	public function test_activate_with_six_hours_uses_custom_schedule(): void {
		CronRegistration::activate( 'six_hours' );
		$this->assertSame( CronRegistration::SCHEDULE_SIX_HOURS, self::$scheduled[0][1] );
	}

	public function test_activate_unknown_freq_falls_back_to_hourly(): void {
		CronRegistration::activate( 'every_blue_moon' );
		$this->assertSame( 'hourly', self::$scheduled[0][1] );
	}

	public function test_reschedule_is_noop_when_already_on_target(): void {
		self::$current_event = (object) array(
			'timestamp' => 1000,
			'schedule'  => 'daily',
			'hook'      => 'semantic_posts_cron_tick',
		);
		CronRegistration::reschedule( 'daily' );
		$this->assertSame( array(), self::$scheduled );
		$this->assertSame( array(), self::$unscheduled );
	}

	public function test_reschedule_unschedules_current_and_schedules_new(): void {
		self::$current_event = (object) array(
			'timestamp' => 1000,
			'schedule'  => 'hourly',
			'hook'      => 'semantic_posts_cron_tick',
		);
		CronRegistration::reschedule( 'daily' );
		$this->assertCount( 1, self::$unscheduled );
		$this->assertCount( 1, self::$scheduled );
		$this->assertSame( 'daily', self::$scheduled[0][1] );
	}
}
