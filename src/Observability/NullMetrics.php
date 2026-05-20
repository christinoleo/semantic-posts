<?php
/**
 * Default Metrics implementation: every recording call is a no-op.
 *
 * Used wherever a real Metrics isn't supplied (existing unit tests, callers
 * outside the cron path). summary24h() returns the zero-state so admin code
 * can render without conditional branches.
 *
 * @package SemanticPosts\Observability
 */

declare( strict_types=1 );

namespace SemanticPosts\Observability;

final class NullMetrics implements Metrics {

	/**
	 * @param int   $tokens Ignored.
	 * @param float $cost   Ignored.
	 */
	public function record_embedding_call( int $tokens, float $cost ): void {}

	/**
	 * @param array $event Ignored.
	 */
	public function record_cron_tick( array $event ): void {}

	/**
	 * @param int $count Ignored.
	 */
	public function record_render_query( int $count ): void {}

	/**
	 * @param int $bytes Ignored.
	 */
	public function record_peak_memory( int $bytes ): void {}

	/** Zero-state summary. */
	public function summary24h(): array {
		return array(
			'embedding_calls'    => 0,
			'embedding_cost_usd' => 0.0,
			'queue_size'         => 0,
			'failed_count'       => 0,
			'last_tick_ts'       => null,
			'last_tick_outcome'  => null,
			'render_query_avg'   => 0.0,
			'render_query_max'   => 0,
			'peak_memory_mb'     => 0.0,
			'recent_ticks'       => array(),
		);
	}
}
