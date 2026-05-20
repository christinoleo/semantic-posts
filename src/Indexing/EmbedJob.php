<?php
/**
 * Wrap Provider::embed with retry/backoff/3-strike classification.
 *
 * Subsystems that need an embedding call into EmbedJob, never into Provider
 * directly. This isolates retry policy + telemetry + AR-10 postmeta writes
 * from the HTTP layer.
 *
 * Outcomes (see Result):
 *   SUCCESS  — embedding written, dirty cleared, hash stored.
 *   RETRY    — RetryableException + attempt < MAX_ATTEMPTS; caller re-queues
 *              with delay = 2^attempt seconds (exponential backoff per FR-3).
 *   FAILED   — fatal exception OR attempts exhausted; post marked failed in
 *              _sp_state, observability counter bumped, no further retries.
 *
 * @package SemanticPosts\Indexing
 */

declare( strict_types=1 );

namespace SemanticPosts\Indexing;

use SemanticPosts\Embeddings\Exception\FatalException;
use SemanticPosts\Embeddings\Exception\RetryableException;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\Provider;
use SemanticPosts\Embeddings\Vector;
use SemanticPosts\Logging;
use WP_Post;

class EmbedJob {

	/**
	 * Maximum attempt count (1-indexed). After the 3rd RetryableException,
	 * the post is marked failed permanently.
	 */
	public const MAX_ATTEMPTS = 3;

	public const OUTCOME_SUCCESS = 'success';
	public const OUTCOME_RETRY   = 'retry';
	public const OUTCOME_FAILED  = 'failed';

	/** @var Provider */
	private Provider $provider;

	/** @var IndexableTextBuilder */
	private IndexableTextBuilder $builder;

	/** @var RateLimiter */
	private RateLimiter $rate_limiter;

	/** @var HashDiffDetector */
	private HashDiffDetector $hash;

	/** @var StateRepository */
	private StateRepository $state;

	/**
	 * @param Provider             $provider     Embedding provider (OpenAIProvider in v1).
	 * @param IndexableTextBuilder $builder      ADR-0001 text composer.
	 * @param RateLimiter          $rate_limiter Shared per-tick rate limiter.
	 * @param HashDiffDetector     $hash         AR-10 owner of _sp_text_hash + _sp_dirty.
	 * @param StateRepository      $state        AR-* owner of _sp_state (metrics + failed list).
	 */
	public function __construct(
		Provider $provider,
		IndexableTextBuilder $builder,
		RateLimiter $rate_limiter,
		HashDiffDetector $hash,
		StateRepository $state
	) {
		$this->provider     = $provider;
		$this->builder      = $builder;
		$this->rate_limiter = $rate_limiter;
		$this->hash         = $hash;
		$this->state        = $state;
	}

	/**
	 * Run the job for one post.
	 *
	 * @param  WP_Post $post    Target post.
	 * @param  int     $attempt 1-indexed attempt number from the caller's queue.
	 * @return array{outcome: string, retry_after_seconds?: int}
	 *
	 *   On RETRY the result contains `retry_after_seconds = 2^attempt`. Caller
	 *   schedules the next attempt that far in the future. On SUCCESS or FAILED
	 *   there is no retry — the queue entry is consumed.
	 */
	public function run( WP_Post $post, int $attempt = 1 ): array {
		$text = $this->builder->build( $post );
		$hash = md5( $text );

		$this->rate_limiter->wait();

		try {
			$embedding = $this->provider->embed( $text );
		} catch ( RetryableException $e ) {
			$this->state->record_retry();
			if ( $attempt >= self::MAX_ATTEMPTS ) {
				Logging::warn(
					'EmbedJob exceeded retry budget — marking failed.',
					array(
						'post_id'  => $post->ID,
						'attempts' => $attempt,
					)
				);
				$this->state->mark_post_failed( $post->ID );
				return array( 'outcome' => self::OUTCOME_FAILED );
			}
			$delay = (int) pow( 2, $attempt );
			return array(
				'outcome'             => self::OUTCOME_RETRY,
				'retry_after_seconds' => $delay,
			);
		} catch ( FatalException $e ) {
			Logging::error(
				'EmbedJob fatal — marking failed without retry.',
				array(
					'post_id' => $post->ID,
					'message' => $e->getMessage(),
				)
			);
			$this->state->mark_post_failed( $post->ID );
			return array( 'outcome' => self::OUTCOME_FAILED );
		}

		Vector::write_embedding( $post->ID, $embedding );
		$this->hash->write_hash( $post->ID, $hash );
		$this->hash->clear_dirty( $post->ID );
		$this->state->record_success();

		return array( 'outcome' => self::OUTCOME_SUCCESS );
	}
}
