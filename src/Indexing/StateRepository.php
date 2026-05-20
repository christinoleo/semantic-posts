<?php
/**
 * Cross-tick state for the indexing pipeline.
 *
 * Persists as a NON-autoloaded option `_sp_state` per architecture decision
 * — autoload would bloat WP's default options query on every page view.
 * Reads/writes are coarse-grained: the whole structure is fetched, mutated,
 * and re-written. That's fine because the indexing tick is the only
 * concurrent writer (single hourly cron event).
 *
 * Structure (extended by TB-07/TB-09/TB-14/TB-13):
 *   {
 *     "cold_start":         { ...phase 1/2 progress... },
 *     "verification":       { "next_due": <ts> },
 *     "dirty_queue_count":  <int>,
 *     "metrics":            { "succeeded": <int>, "retried": <int>, "failed": <int> },
 *     "failed_posts":       { <post_id>: <last_attempt_ts>, ... },
 *   }
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

class StateRepository {

	public const OPTION_NAME = '_sp_state';

	/**
	 * Default shape — used to merge into whatever the DB currently holds so
	 * future keys added by later slices don't surprise older sites.
	 *
	 * @var array<string,mixed>
	 */
	private const DEFAULTS = array(
		'cold_start'        => array(),
		'verification'      => array(),
		'dirty_queue_count' => 0,
		'metrics'           => array(
			'succeeded' => 0,
			'retried'   => 0,
			'failed'    => 0,
		),
		'failed_posts'      => array(),
	);

	/**
	 * @return array<string,mixed>
	 */
	public function read(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_replace_recursive( self::DEFAULTS, $stored );
	}

	/**
	 * Replace the persisted state wholesale. Caller is expected to have read,
	 * mutated, then written — concurrent ticks should not happen by design but
	 * an `update_option` overwrite is the worst-case loss boundary.
	 *
	 * @param array<string,mixed> $state Full state document.
	 */
	public function write( array $state ): void {
		// add_option with autoload='no' on first write; update_option preserves the autoload flag.
		if ( false === get_option( self::OPTION_NAME, false ) ) {
			add_option( self::OPTION_NAME, $state, '', 'no' );
			return;
		}
		update_option( self::OPTION_NAME, $state );
	}

	/**
	 * Record a post as failed (3-strike or fatal) and bump the metrics counter.
	 *
	 * @param int $post_id Failed post.
	 */
	public function mark_post_failed( int $post_id ): void {
		$state                                      = $this->read();
		$state['failed_posts'][ (string) $post_id ] = time();
		$state['metrics']['failed']                 = (int) $state['metrics']['failed'] + 1;
		$this->write( $state );
	}

	/**
	 * Bump the success metric. Called by EmbedJob on Provider::embed return.
	 */
	public function record_success(): void {
		$state                         = $this->read();
		$state['metrics']['succeeded'] = (int) $state['metrics']['succeeded'] + 1;
		$this->write( $state );
	}

	/**
	 * Bump the retried metric. Called by EmbedJob on RetryableException
	 * (regardless of whether this is attempt 1, 2, or 3).
	 */
	public function record_retry(): void {
		$state                       = $this->read();
		$state['metrics']['retried'] = (int) $state['metrics']['retried'] + 1;
		$this->write( $state );
	}
}
