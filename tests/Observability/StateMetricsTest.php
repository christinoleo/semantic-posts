<?php
/**
 * Coverage for TB-13 StateMetrics — daily-bucket arithmetic, ring buffer,
 * render-query window roll, 25h prune, and summary projection.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Observability;

use PHPUnit\Framework\TestCase;
use SemanticPosts\Observability\StateMetrics;

require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';

use SemanticPosts\Tests\Indexing\InMemoryState;

final class StateMetricsTest extends TestCase {

	public function test_record_embedding_call_accumulates_in_daily_bucket(): void {
		$state = new InMemoryState();
		$now   = (int) strtotime( '2026-05-20 12:00:00 UTC' );
		$clock = static fn(): int => $now;
		$today = gmdate( 'Y-m-d', $now );

		$m = new StateMetrics( $state, $clock );
		$m->record_embedding_call( 100, 0.002 );
		$m->record_embedding_call( 50, 0.001 );

		$bucket = $state->state['metrics']['daily_calls'][ $today ] ?? null;
		$this->assertNotNull( $bucket );
		$this->assertSame( 2, $bucket['calls'] );
		$this->assertSame( 150, $bucket['tokens'] );
		$this->assertEqualsWithDelta( 0.003, $bucket['cost'], 0.0001 );
	}

	public function test_old_daily_buckets_pruned_on_write(): void {
		$now    = (int) strtotime( '2026-05-20 12:00:00 UTC' );
		$today  = gmdate( 'Y-m-d', $now );
		$old    = gmdate( 'Y-m-d', $now - 5 * 86400 );
		$state  = new InMemoryState();
		$state->state['metrics']['daily_calls'] = array(
			$old => array( 'calls' => 99, 'cost' => 0.99, 'tokens' => 9999 ),
		);
		$m = new StateMetrics( $state, static fn(): int => $now );
		$m->record_embedding_call( 1, 0.0001 );

		$this->assertArrayNotHasKey( $old, $state->state['metrics']['daily_calls'] );
		$this->assertArrayHasKey( $today, $state->state['metrics']['daily_calls'] );
	}

	public function test_cron_tick_ring_buffer_capped_at_24(): void {
		$state = new InMemoryState();
		$now   = 1779696000;
		$m     = new StateMetrics( $state, static fn(): int => $now );
		for ( $i = 0; $i < 30; $i++ ) {
			$m->record_cron_tick(
				array(
					'processed'         => $i,
					'outcome'           => 'ok',
					'halted_for_memory' => false,
				)
			);
		}
		$this->assertCount( 24, $state->state['metrics']['recent_ticks'] );
		// Oldest kept entry should have processed=6 (since 0..5 were evicted).
		$this->assertSame( 6, $state->state['metrics']['recent_ticks'][0]['processed'] );
		$this->assertSame( 29, $state->state['metrics']['recent_ticks'][23]['processed'] );
	}

	public function test_render_query_running_avg_and_max(): void {
		$state = new InMemoryState();
		$m     = new StateMetrics( $state, static fn(): int => 1779696000 );
		$m->record_render_query( 2 );
		$m->record_render_query( 4 );
		$m->record_render_query( 1 );

		$window = $state->state['metrics']['render_query'];
		$this->assertSame( 3, $window['count'] );
		$this->assertSame( 7, $window['sum'] );
		$this->assertSame( 4, $window['max'] );
	}

	public function test_render_query_window_rolls_after_24h(): void {
		$state  = new InMemoryState();
		$call   = 0;
		$times  = array( 1000, 1000, 1000 + 86401 ); // third call 24h+1s later.
		$clock  = static function () use ( &$call, $times ): int {
			$t = $times[ $call ] ?? end( $times );
			++$call;
			return $t;
		};
		$m = new StateMetrics( $state, $clock );
		$m->record_render_query( 5 );
		$m->record_render_query( 6 );
		$m->record_render_query( 2 ); // roll happens here.

		$window = $state->state['metrics']['render_query'];
		$this->assertSame( 1, $window['count'], 'Window should have rolled.' );
		$this->assertSame( 2, $window['sum'] );
		$this->assertSame( 2, $window['max'] );
	}

	public function test_record_peak_memory_keeps_24h_max(): void {
		$state = new InMemoryState();
		$m     = new StateMetrics( $state, static fn(): int => 1779696000 );
		$m->record_peak_memory( 50_000_000 );
		$m->record_peak_memory( 100_000_000 );
		$m->record_peak_memory( 75_000_000 );

		$this->assertSame( 100_000_000, $state->state['metrics']['peak_memory']['max_bytes'] );
	}

	public function test_summary24h_projects_full_shape(): void {
		$state = new InMemoryState();
		$state->state['dirty_queue_count'] = 7;
		$state->state['failed_posts']      = array( '1' => 123, '2' => 456 );
		$now                                = 1779696000;

		$m = new StateMetrics( $state, static fn(): int => $now );
		$m->record_embedding_call( 1000, 0.020 );
		$m->record_cron_tick(
			array(
				'processed'         => 3,
				'outcome'           => 'ok',
				'halted_for_memory' => false,
			)
		);
		$m->record_render_query( 2 );
		$m->record_render_query( 6 );
		$m->record_peak_memory( 50 * 1024 * 1024 );

		$summary = $m->summary24h();
		$this->assertSame( 1, $summary['embedding_calls'] );
		$this->assertEqualsWithDelta( 0.020, $summary['embedding_cost_usd'], 0.0001 );
		$this->assertSame( 7, $summary['queue_size'] );
		$this->assertSame( 2, $summary['failed_count'] );
		$this->assertSame( $now, $summary['last_tick_ts'] );
		$this->assertSame( 'ok', $summary['last_tick_outcome'] );
		$this->assertSame( 4.0, $summary['render_query_avg'] );
		$this->assertSame( 6, $summary['render_query_max'] );
		$this->assertSame( 50.0, $summary['peak_memory_mb'] );
		$this->assertCount( 1, $summary['recent_ticks'] );
	}

	public function test_summary24h_handles_empty_state_cleanly(): void {
		$state = new InMemoryState();
		$m     = new StateMetrics( $state, static fn(): int => 1779696000 );
		$summary = $m->summary24h();
		$this->assertSame( 0, $summary['embedding_calls'] );
		$this->assertSame( 0.0, $summary['embedding_cost_usd'] );
		$this->assertNull( $summary['last_tick_ts'] );
		$this->assertSame( array(), $summary['recent_ticks'] );
	}
}
