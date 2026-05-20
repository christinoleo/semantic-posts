<?php
/**
 * Drains the install-time corpus into the Similarity Graph (ADR-0008).
 *
 * State machine (persisted in `_sp_state.cold_start`):
 *   idle      → no work pending (initial + final).
 *   bootstrap → Phase 1 active: corpus has ≤ N_b indexed posts; new inserts
 *               brute-force pairwise.
 *   graph_knn → Phase 2 active: corpus has > N_b indexed; new inserts use
 *               greedy graph walk.
 *
 * Transitions:
 *   idle → bootstrap on first batch with work.
 *   bootstrap → graph_knn when indexed count reaches N_b.
 *   graph_knn → idle when UnindexedQueue::count() == 0.
 *
 * The processor is resumable across PHP deaths: `last_processed_id` is
 * persisted after every successful post. A killed tick re-enters at the
 * next post in ID order on the next run.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Logging;

class ColdStartProcessor {

	public const PHASE_IDLE      = 'idle';
	public const PHASE_BOOTSTRAP = 'bootstrap';
	public const PHASE_GRAPH_KNN = 'graph_knn';

	public const POSTS_PER_BATCH = 50;

	/** @var UnindexedQueue */
	private UnindexedQueue $queue;

	/** @var EmbedJob */
	private EmbedJob $embed_job;

	/** @var Crawler */
	private Crawler $crawler;

	/** @var StateRepository */
	private StateRepository $state;

	/** @var MemoryGuard */
	private MemoryGuard $memory;

	/**
	 * @param UnindexedQueue  $queue     Source of next-batch IDs.
	 * @param EmbedJob        $embed_job Per-post embed runner.
	 * @param Crawler         $crawler   Graph maintainer (calls insert).
	 * @param StateRepository $state     Persists cold_start phase + cursor.
	 * @param MemoryGuard     $memory    Halt signal.
	 */
	public function __construct(
		UnindexedQueue $queue,
		EmbedJob $embed_job,
		Crawler $crawler,
		StateRepository $state,
		MemoryGuard $memory
	) {
		$this->queue     = $queue;
		$this->embed_job = $embed_job;
		$this->crawler   = $crawler;
		$this->state     = $state;
		$this->memory    = $memory;
	}

	/**
	 * Whether the cold-start phase still has work to do. TickProcessor calls
	 * this to decide whether to invoke `run_batch` before the dirty-queue
	 * branch.
	 */
	public function is_active(): bool {
		$state = $this->state->read();
		$phase = (string) ( $state['cold_start']['phase'] ?? self::PHASE_IDLE );
		return self::PHASE_IDLE !== $phase || $this->queue->count() > 0;
	}

	/**
	 * Kick off a cold-start run. Resets the cursor + transitions phase to
	 * bootstrap and schedules a single tick event so the user sees progress
	 * without waiting for the next hourly cron.
	 *
	 * Returns whether the start was actually triggered. False = there was no
	 * pending work, so the call is a no-op (UI can show "nothing to index").
	 */
	public function start(): bool {
		if ( 0 === $this->queue->count() ) {
			return false;
		}
		$state                                    = $this->state->read();
		$state['cold_start']['phase']             = self::PHASE_BOOTSTRAP;
		$state['cold_start']['last_processed_id'] = 0;
		$state['cold_start']['started']           = time();
		unset( $state['cold_start']['completed'] );
		$this->state->write( $state );

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time() + 5, TickProcessor::HOOK );
		}

		return true;
	}

	/**
	 * Snapshot used by the admin progress AJAX endpoint.
	 *
	 * @return array{phase:string, last_processed_id:int, indexed_count:int, pending_count:int, started_at:int|null, completed_at:int|null}
	 */
	public function progress(): array {
		$state = $this->state->read();
		$cold  = is_array( $state['cold_start'] ?? null ) ? $state['cold_start'] : array();
		return array(
			'phase'             => (string) ( $cold['phase'] ?? self::PHASE_IDLE ),
			'last_processed_id' => (int) ( $cold['last_processed_id'] ?? 0 ),
			'indexed_count'     => $this->crawler_indexed_count(),
			'pending_count'     => $this->queue->count(),
			'started_at'        => isset( $cold['started'] ) ? (int) $cold['started'] : null,
			'completed_at'      => isset( $cold['completed'] ) ? (int) $cold['completed'] : null,
		);
	}

	/**
	 * Process up to POSTS_PER_BATCH unindexed posts, persisting progress
	 * after each so a killed tick resumes mid-batch.
	 *
	 * @return array{processed:int, halted_for_memory:bool}
	 */
	public function run_batch(): array {
		$state             = $this->state->read();
		$cold              = $state['cold_start'] ?? array();
		$phase             = (string) ( $cold['phase'] ?? self::PHASE_IDLE );
		$last_id           = (int) ( $cold['last_processed_id'] ?? 0 );
		$processed         = 0;
		$halted_for_memory = false;

		$batch = $this->queue->next_batch( self::POSTS_PER_BATCH, $last_id );
		if ( empty( $batch ) ) {
			$state                                    = $this->state->read();
			$state['cold_start']['phase']             = self::PHASE_IDLE;
			$state['cold_start']['last_processed_id'] = 0;
			$this->state->write( $state );
			return array(
				'processed'         => 0,
				'halted_for_memory' => false,
			);
		}

		if ( self::PHASE_IDLE === $phase ) {
			$phase                          = self::PHASE_BOOTSTRAP;
			$state                          = $this->state->read();
			$state['cold_start']['phase']   = $phase;
			$state['cold_start']['started'] = time();
			$this->state->write( $state );
			Logging::info( 'Cold start beginning Phase 1 bootstrap.' );
		}

		foreach ( $batch as $post_id ) {
			if ( $this->memory->should_halt() ) {
				$halted_for_memory = true;
				break;
			}

			$post = function_exists( 'get_post' ) ? get_post( $post_id ) : null;
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			$outcome = $this->embed_job->run( $post, 1, true );
			if ( EmbedJob::OUTCOME_SUCCESS === $outcome['outcome'] ) {
				$this->crawler->insert( $post->ID );
				++$processed;
				// Re-read state so the metric increments (succeeded/retried) +
				// failed_posts mutations made by EmbedJob / record_success /
				// mark_post_failed are preserved across this cursor write.
				$state                                    = $this->state->read();
				$state['cold_start']['last_processed_id'] = $post->ID;
				$this->state->write( $state );
			}
		}

		// Possible phase transition (bootstrap → graph_knn).
		$state         = $this->state->read();
		$indexed_count = $this->crawler_indexed_count();
		if ( self::PHASE_BOOTSTRAP === $phase && $indexed_count >= Crawler::PHASE_1_LIMIT ) {
			$state['cold_start']['phase'] = self::PHASE_GRAPH_KNN;
			Logging::info(
				'Cold start transitioning to Phase 2 graph kNN walk.',
				array( 'indexed_count' => $indexed_count )
			);
		}

		// Drained? Only mark complete if we didn't halt — a halted tick may have
		// left items behind that aren't reflected in queue->count() yet.
		if ( ! $halted_for_memory && 0 === $this->queue->count() ) {
			$state['cold_start']['phase']             = self::PHASE_IDLE;
			$state['cold_start']['last_processed_id'] = 0;
			$state['cold_start']['completed']         = time();
			Logging::info( 'Cold start complete.', array( 'indexed_count' => $indexed_count ) );
		}

		$this->state->write( $state );
		return array(
			'processed'         => $processed,
			'halted_for_memory' => $halted_for_memory,
		);
	}

	/**
	 * Count of posts currently in the graph. Indirection point so a future
	 * dedicated counter can replace the list-and-count helper without changing
	 * ColdStartProcessor's flow.
	 */
	private function crawler_indexed_count(): int {
		return $this->crawler->indexed_count();
	}
}
