<?php
/**
 * Production Metrics impl — persists via StateRepository.
 *
 * Storage layout under `_sp_state.metrics`:
 *
 *   daily_calls: { 'YYYY-MM-DD': { calls: N, cost: F, tokens: N } }   ← 25h pruned
 *   recent_ticks: [ { ts, processed, outcome, halted_for_memory }, ... ]  ← ring buffer 24
 *   render_query: { count: N, sum: N, max: N, window_start: ts }     ← 24h moving
 *   peak_memory:  { max_bytes: N, window_start: ts }                 ← 24h max
 *
 * summary24h() projects these into the shape the admin panel reads.
 *
 * @package SemanticPosts\Observability
 */

declare( strict_types=1 );

namespace SemanticPosts\Observability;

use SemanticPosts\Indexing\StateRepository;

final class StateMetrics implements Metrics {

	public const RING_SIZE          = 24;
	public const DAILY_PRUNE_HOURS  = 25;
	public const RENDER_WINDOW_SECS = 86400;
	public const PEAK_WINDOW_SECS   = 86400;

	/** @var StateRepository */
	private StateRepository $state;

	/** @var callable():int */
	private $clock;

	/**
	 * @param StateRepository $state Cross-tick state owner.
	 * @param callable|null   $clock Test seam — returns "now" in unix seconds.
	 */
	public function __construct( StateRepository $state, ?callable $clock = null ) {
		$this->state = $state;
		$this->clock = $clock ?? static fn(): int => time();
	}

	/**
	 * @param int   $tokens Tokens billed by the provider.
	 * @param float $cost   Estimated USD cost.
	 */
	public function record_embedding_call( int $tokens, float $cost ): void {
		$state  = $this->state->read();
		$bucket = gmdate( 'Y-m-d', ( $this->clock )() );

		$daily = is_array( $state['metrics']['daily_calls'] ?? null ) ? $state['metrics']['daily_calls'] : array();
		if ( ! isset( $daily[ $bucket ] ) ) {
			$daily[ $bucket ] = array(
				'calls'  => 0,
				'cost'   => 0.0,
				'tokens' => 0,
			);
		}
		$daily[ $bucket ]['calls']  = (int) $daily[ $bucket ]['calls'] + 1;
		$daily[ $bucket ]['cost']   = (float) $daily[ $bucket ]['cost'] + max( 0.0, $cost );
		$daily[ $bucket ]['tokens'] = (int) $daily[ $bucket ]['tokens'] + max( 0, $tokens );

		$daily = $this->prune_daily( $daily );

		$state['metrics']['daily_calls'] = $daily;
		$this->state->write( $state );
	}

	/**
	 * @param array{processed:int, halted_for_memory:bool, outcome:string} $event Tick event.
	 */
	public function record_cron_tick( array $event ): void {
		$state = $this->state->read();

		$ring   = is_array( $state['metrics']['recent_ticks'] ?? null ) ? $state['metrics']['recent_ticks'] : array();
		$ring[] = array(
			'ts'                => ( $this->clock )(),
			'processed'         => (int) ( $event['processed'] ?? 0 ),
			'outcome'           => (string) ( $event['outcome'] ?? 'ok' ),
			'halted_for_memory' => (bool) ( $event['halted_for_memory'] ?? false ),
		);
		if ( count( $ring ) > self::RING_SIZE ) {
			$ring = array_slice( $ring, -self::RING_SIZE );
		}
		$state['metrics']['recent_ticks'] = $ring;
		$this->state->write( $state );
	}

	/**
	 * @param int $count Queries issued during the render.
	 */
	public function record_render_query( int $count ): void {
		if ( $count < 0 ) {
			$count = 0;
		}
		$state   = $this->state->read();
		$window  = is_array( $state['metrics']['render_query'] ?? null ) ? $state['metrics']['render_query'] : array();
		$now     = ( $this->clock )();
		$start   = (int) ( $window['window_start'] ?? $now );
		$samples = (int) ( $window['count'] ?? 0 );
		$sum     = (int) ( $window['sum'] ?? 0 );
		$max     = (int) ( $window['max'] ?? 0 );

		if ( $now - $start > self::RENDER_WINDOW_SECS ) {
			$samples = 0;
			$sum     = 0;
			$max     = 0;
			$start   = $now;
		}
		++$samples;
		$sum += $count;
		if ( $count > $max ) {
			$max = $count;
		}

		$state['metrics']['render_query'] = array(
			'count'        => $samples,
			'sum'          => $sum,
			'max'          => $max,
			'window_start' => $start,
		);
		$this->state->write( $state );
	}

	/**
	 * @param int $bytes Peak memory bytes (memory_get_peak_usage(true)).
	 */
	public function record_peak_memory( int $bytes ): void {
		if ( $bytes < 0 ) {
			$bytes = 0;
		}
		$state  = $this->state->read();
		$window = is_array( $state['metrics']['peak_memory'] ?? null ) ? $state['metrics']['peak_memory'] : array();
		$now    = ( $this->clock )();
		$start  = (int) ( $window['window_start'] ?? $now );
		$max    = (int) ( $window['max_bytes'] ?? 0 );

		if ( $now - $start > self::PEAK_WINDOW_SECS ) {
			$max   = 0;
			$start = $now;
		}
		if ( $bytes > $max ) {
			$max = $bytes;
		}

		$state['metrics']['peak_memory'] = array(
			'max_bytes'    => $max,
			'window_start' => $start,
		);
		$this->state->write( $state );
	}

	/**
	 * Projection for the admin panel.
	 */
	public function summary24h(): array {
		$state   = $this->state->read();
		$metrics = is_array( $state['metrics'] ?? null ) ? $state['metrics'] : array();

		$daily = is_array( $metrics['daily_calls'] ?? null ) ? $this->prune_daily( $metrics['daily_calls'] ) : array();
		$calls = 0;
		$cost  = 0.0;
		foreach ( $daily as $row ) {
			$calls += (int) ( $row['calls'] ?? 0 );
			$cost  += (float) ( $row['cost'] ?? 0 );
		}

		$ring = is_array( $metrics['recent_ticks'] ?? null ) ? $metrics['recent_ticks'] : array();
		$last = end( $ring );
		if ( false === $last ) {
			$last = null;
		}
		$last_ts  = is_array( $last ) ? (int) ( $last['ts'] ?? 0 ) : null;
		$last_out = is_array( $last ) ? (string) ( $last['outcome'] ?? 'unknown' ) : null;

		$render = is_array( $metrics['render_query'] ?? null ) ? $metrics['render_query'] : array();
		$rc     = (int) ( $render['count'] ?? 0 );
		$rsum   = (int) ( $render['sum'] ?? 0 );
		$rmax   = (int) ( $render['max'] ?? 0 );
		$ravg   = $rc > 0 ? round( $rsum / $rc, 2 ) : 0.0;

		$peak    = is_array( $metrics['peak_memory'] ?? null ) ? $metrics['peak_memory'] : array();
		$peak_mb = round( ( (int) ( $peak['max_bytes'] ?? 0 ) ) / 1048576, 2 );

		$failed = is_array( $state['failed_posts'] ?? null ) ? count( $state['failed_posts'] ) : 0;

		return array(
			'embedding_calls'    => $calls,
			'embedding_cost_usd' => round( $cost, 4 ),
			'queue_size'         => (int) ( $state['dirty_queue_count'] ?? 0 ),
			'failed_count'       => $failed,
			'last_tick_ts'       => $last_ts,
			'last_tick_outcome'  => $last_out,
			'render_query_avg'   => $ravg,
			'render_query_max'   => $rmax,
			'peak_memory_mb'     => $peak_mb,
			'recent_ticks'       => array_values( $ring ),
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $daily Daily buckets keyed by Y-m-d.
	 * @return array<string,array<string,mixed>>
	 */
	private function prune_daily( array $daily ): array {
		$cutoff_ts = ( $this->clock )() - ( self::DAILY_PRUNE_HOURS * 3600 );
		foreach ( array_keys( $daily ) as $bucket ) {
			$ts = strtotime( $bucket . ' 00:00:00 +0000' );
			if ( false === $ts ) {
				unset( $daily[ $bucket ] );
				continue;
			}
			if ( $ts < $cutoff_ts ) {
				unset( $daily[ $bucket ] );
			}
		}
		return $daily;
	}
}
