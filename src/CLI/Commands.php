<?php
/**
 * `wp semantic-posts` CLI surface (TB-15).
 *
 * Mirrors the admin operations so operators on hosts with WP-Cron disabled can
 * drive the indexer from the shell. Each subcommand reuses the same components
 * the admin page does — no parallel pipeline.
 *
 * Subcommands:
 *   index         — drain the unindexed corpus synchronously (cold-start).
 *   reindex       — wipe `_sp_*` postmeta + run index.
 *   process-dirty — manually drain the dirty queue (one tick).
 *   verify        — run a verification pass; print MRD.
 *   retry-failed  — clear failed flags and re-enqueue.
 *   status        — print `Metrics::summary24h()` in `--format=table|json`.
 *
 * @package SemanticPosts\CLI
 */

declare( strict_types=1 );

namespace SemanticPosts\CLI;

use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\TickProcessor;
use SemanticPosts\Indexing\Wiper;
use SemanticPosts\Observability\Metrics;
use SemanticPosts\Verification\VerificationPass;

class Commands {

	/** @var ColdStartProcessor */
	private ColdStartProcessor $cold_start;
	/** @var Wiper */
	private Wiper $wiper;
	/** @var TickProcessor */
	private TickProcessor $tick_processor;
	/** @var VerificationPass */
	private VerificationPass $verification;
	/** @var StateRepository */
	private StateRepository $state;
	/** @var HashDiffDetector */
	private HashDiffDetector $hash;
	/** @var Metrics */
	private Metrics $metrics;

	/**
	 * @param ColdStartProcessor $cold_start     Cold-start drain.
	 * @param Wiper              $wiper          Postmeta wipe (used by reindex).
	 * @param TickProcessor      $tick_processor Single-tick runner (process-dirty).
	 * @param VerificationPass   $verification   MRD drift check.
	 * @param StateRepository    $state          State repo (retry-failed source).
	 * @param HashDiffDetector   $hash           Dirty-bit owner (retry-failed sink).
	 * @param Metrics            $metrics        Read-side metrics (status command).
	 */
	public function __construct(
		ColdStartProcessor $cold_start,
		Wiper $wiper,
		TickProcessor $tick_processor,
		VerificationPass $verification,
		StateRepository $state,
		HashDiffDetector $hash,
		Metrics $metrics
	) {
		$this->cold_start     = $cold_start;
		$this->wiper          = $wiper;
		$this->tick_processor = $tick_processor;
		$this->verification   = $verification;
		$this->state          = $state;
		$this->hash           = $hash;
		$this->metrics        = $metrics;
	}

	/**
	 * Drain the unindexed corpus synchronously.
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts index
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function index( array $args = array(), array $assoc_args = array() ): void {
		unset( $args, $assoc_args );

		if ( ! $this->cold_start->is_active() ) {
			\WP_CLI::warning( 'No pending posts to index.' );
			return;
		}

		$total = 0;
		while ( $this->cold_start->is_active() ) {
			$result = $this->cold_start->run_batch();
			$total += (int) $result['processed'];
			\WP_CLI::log(
				sprintf(
					'processed=%d halted_for_memory=%s',
					(int) $result['processed'],
					$result['halted_for_memory'] ? 'yes' : 'no'
				)
			);
			if ( $result['halted_for_memory'] ) {
				\WP_CLI::warning( 'Halted for memory; rerun after lowering load.' );
				return;
			}
			if ( 0 === (int) $result['processed'] ) {
				break;
			}
		}
		\WP_CLI::success( sprintf( 'Indexed %d post(s).', $total ) );
	}

	/**
	 * Wipe all `_sp_*` postmeta and run a fresh cold-start.
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts reindex
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function reindex( array $args = array(), array $assoc_args = array() ): void {
		$rows = $this->wiper->wipe_embeddings();
		\WP_CLI::log( sprintf( 'Wiped %d postmeta rows.', $rows ) );
		$this->index( $args, $assoc_args );
	}

	/**
	 * Drain the dirty queue (one tick).
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts process-dirty
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function process_dirty( array $args = array(), array $assoc_args = array() ): void {
		unset( $args, $assoc_args );
		$result = $this->tick_processor->run();
		\WP_CLI::success(
			sprintf(
				'Tick complete: processed=%d, halted_for_memory=%s.',
				(int) $result['processed'],
				$result['halted_for_memory'] ? 'yes' : 'no'
			)
		);
	}

	/**
	 * Run a verification pass and print MRD.
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts verify
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function verify( array $args = array(), array $assoc_args = array() ): void {
		unset( $args, $assoc_args );
		$result = $this->verification->run();
		\WP_CLI::success(
			sprintf(
				'MRD = %0.2f (threshold %0.2f, drift=%s, sampled=%d).',
				(float) $result['mrd'],
				(float) $result['threshold'],
				$result['drift'] ? 'YES' : 'NO',
				(int) $result['sampled']
			)
		);
	}

	/**
	 * Clear failed flags and re-enqueue all failed posts.
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts retry-failed
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (unused).
	 */
	public function retry_failed( array $args = array(), array $assoc_args = array() ): void {
		unset( $args, $assoc_args );
		$state  = $this->state->read();
		$failed = is_array( $state['failed_posts'] ?? null ) ? array_keys( $state['failed_posts'] ) : array();
		foreach ( $failed as $pid ) {
			$this->hash->mark_dirty( (int) $pid );
		}
		$state['failed_posts']      = array();
		$state['metrics']['failed'] = 0;
		$this->state->write( $state );
		\WP_CLI::success( sprintf( 'Re-enqueued %d failed post(s).', count( $failed ) ) );
	}

	/**
	 * Print Metrics::summary24h() to stdout.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. table | json. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp semantic-posts status --format=json
	 *
	 * @param string[]             $args       Positional args (unused).
	 * @param array<string,string> $assoc_args Flags (--format).
	 */
	public function status( array $args = array(), array $assoc_args = array() ): void {
		unset( $args );
		$summary = $this->metrics->summary24h();
		$format  = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			\WP_CLI::print_value( $summary, array( 'format' => 'json' ) );
			return;
		}
		\WP_CLI::print_value( $summary, array( 'format' => 'yaml' ) );
	}
}
