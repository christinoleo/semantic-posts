<?php
/**
 * Observability surface (TB-13) — collects the counters the admin panel reads.
 *
 * Implementations:
 *   StateMetrics — production. Writes into `_sp_state.metrics` via StateRepository.
 *   NullMetrics  — default for unit tests / pre-TB-13 callers; every method is a no-op.
 *
 * Recording layers:
 *   record_embedding_call() — called by EmbedJob on every successful Provider::embed.
 *                             Aggregated into a per-day bucket keyed Y-m-d. Buckets
 *                             older than 25h are pruned on every write.
 *   record_cron_tick()      — called by TickProcessor at the end of each run. Stored
 *                             as a ring buffer of 24 events.
 *   record_render_query()   — called by SourceResolver after each WP_Query. Aggregated
 *                             into running avg + max.
 *   record_peak_memory()    — called by TickProcessor at the end of each run with the
 *                             current peak memory usage in bytes (24h max).
 *
 * @package SemanticPosts\Observability
 */

declare( strict_types=1 );

namespace SemanticPosts\Observability;

interface Metrics {

	/**
	 * Record a successful embedding call.
	 *
	 * @param int   $tokens Number of tokens billed by the provider.
	 * @param float $cost   Estimated USD cost for this call.
	 */
	public function record_embedding_call( int $tokens, float $cost ): void;

	/**
	 * Record a TickProcessor::run completion.
	 *
	 * @param array{processed:int, halted_for_memory:bool, outcome:string} $event Tick event.
	 */
	public function record_cron_tick( array $event ): void;

	/**
	 * Record the number of WP_Query calls a single render-path took.
	 *
	 * @param int $count Queries issued during the render.
	 */
	public function record_render_query( int $count ): void;

	/**
	 * Record current peak memory usage (bytes). 24h max is kept.
	 *
	 * @param int $bytes memory_get_peak_usage(true).
	 */
	public function record_peak_memory( int $bytes ): void;

	/**
	 * Return the panel's read-side projection.
	 *
	 * @return array{
	 *     embedding_calls:int,
	 *     embedding_cost_usd:float,
	 *     queue_size:int,
	 *     failed_count:int,
	 *     last_tick_ts:int|null,
	 *     last_tick_outcome:string|null,
	 *     render_query_avg:float,
	 *     render_query_max:int,
	 *     peak_memory_mb:float,
	 *     recent_ticks:array<int,array<string,mixed>>
	 * }
	 */
	public function summary24h(): array;
}
