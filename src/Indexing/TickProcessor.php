<?php
/**
 * Hourly cron callback — drains pending indexing work.
 *
 * Work-stealing order:
 *   1. Cold-start batch — placeholder; TB-09 fills with phase-1 bootstrap +
 *      phase-2 graph-traversal kNN walk.
 *   2. Dirty queue — up to MAX_DIRTY_PER_TICK posts via DirtyQueue::next_batch.
 *   3. Verification pass — placeholder; TB-14 fills with MRD-drift detection.
 *
 * Between each post (and between sub-units), the tick consults MemoryGuard.
 * If we're over 80% of WP_MEMORY_LIMIT, the loop exits cleanly so that
 * StateRepository::write and the cron infrastructure get to run before WP's
 * fatal OOM. Whatever is still dirty just waits for the next tick.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Logging;

class TickProcessor {

	public const HOOK               = 'semantic_posts_cron_tick';
	public const MAX_DIRTY_PER_TICK = 50;

	/** @var DirtyQueue */
	private DirtyQueue $queue;

	/** @var EmbedJob */
	private EmbedJob $embed_job;

	/** @var MemoryGuard */
	private MemoryGuard $memory;

	/** @var StateRepository */
	private StateRepository $state;

	/** @var ColdStartProcessor|null */
	private ?ColdStartProcessor $cold_start;

	/**
	 * @param DirtyQueue              $queue      Dirty-post queue.
	 * @param EmbedJob                $embed_job  Embed-job runner.
	 * @param MemoryGuard             $memory     Memory budget guard.
	 * @param StateRepository         $state      Cross-tick state owner.
	 * @param ColdStartProcessor|null $cold_start Optional cold-start drain (TB-09). Null = warm-only.
	 */
	public function __construct(
		DirtyQueue $queue,
		EmbedJob $embed_job,
		MemoryGuard $memory,
		StateRepository $state,
		?ColdStartProcessor $cold_start = null
	) {
		$this->queue      = $queue;
		$this->embed_job  = $embed_job;
		$this->memory     = $memory;
		$this->state      = $state;
		$this->cold_start = $cold_start;
	}

	/**
	 * Run one tick. Returns a small summary used in tests + observability.
	 *
	 * @return array{processed:int, halted_for_memory:bool}
	 */
	public function run(): array {
		$processed         = 0;
		$halted_for_memory = false;

		// Phase 1: cold-start drain (TB-09).
		if ( $this->memory->should_halt() ) {
			$this->persist_state();
			return array(
				'processed'         => $processed,
				'halted_for_memory' => true,
			);
		}

		if ( null !== $this->cold_start && $this->cold_start->is_active() ) {
			$result            = $this->cold_start->run_batch();
			$processed        += (int) $result['processed'];
			$halted_for_memory = $halted_for_memory || (bool) $result['halted_for_memory'];

			// If cold-start did real work this tick, skip the dirty-queue branch
			// so we don't blow the memory budget. The next tick will drain dirties.
			if ( $processed > 0 || $halted_for_memory ) {
				$this->persist_state();
				return array(
					'processed'         => $processed,
					'halted_for_memory' => $halted_for_memory,
				);
			}
		}

		// Phase 2: dirty queue.
		$batch = $this->queue->next_batch( self::MAX_DIRTY_PER_TICK );
		foreach ( $batch as $post_id ) {
			if ( $this->memory->should_halt() ) {
				$halted_for_memory = true;
				break;
			}

			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$this->embed_job->run( $post, 1 );
			++$processed;
		}

		// Phase 3: verification (placeholder — TB-14 fills).
		// Future: if state['verification']['next_due'] <= time(), run a verification pass.

		$this->persist_state();
		return array(
			'processed'         => $processed,
			'halted_for_memory' => $halted_for_memory,
		);
	}

	/**
	 * Persist whatever counters / cursors are in StateRepository so the next
	 * tick can resume cleanly.
	 */
	private function persist_state(): void {
		// EmbedJob already writes through StateRepository on every success/retry/
		// fail, so a read+write here is just a touchpoint for future cursors
		// (cold_start progress, verification cursor) added by TB-09 / TB-14.
		$state                      = $this->state->read();
		$state['dirty_queue_count'] = $this->queue->count();
		$this->state->write( $state );
		Logging::info( 'TickProcessor persisted state.', array( 'dirty_remaining' => $state['dirty_queue_count'] ) );
	}
}
