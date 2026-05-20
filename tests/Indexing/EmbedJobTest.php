<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\Exception\FatalException;
use SemanticPosts\Embeddings\Exception\RetryableException;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\Provider;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\StateRepository;

/** Configurable provider that lets each test script the response per call. */
final class ScriptedProvider implements Provider {
	/** @var array<int,mixed> */
	public array $script = array();
	public int $calls   = 0;

	public function name(): string {
		return 'scripted';
	}
	public function maxInputTokens(): int {
		return 8192;
	}
	public function costPerMillionTokens(): float {
		return 0.0;
	}
	public function embed( string $text ): array {
		$reaction = $this->script[ $this->calls ] ?? array_fill( 0, 1536, 0.0 );
		++$this->calls;
		if ( $reaction instanceof \Throwable ) {
			throw $reaction;
		}
		return $reaction;
	}
}

/** In-memory StateRepository test double — avoids touching get_option/add_option. */
final class InMemoryState extends StateRepository {
	/** @var array<string,mixed> */
	public array $state;

	public function __construct() {
		$this->state = array(
			'cold_start'        => array(),
			'verification'      => array(),
			'dirty_queue_count' => 0,
			'metrics'           => array( 'succeeded' => 0, 'retried' => 0, 'failed' => 0 ),
			'failed_posts'      => array(),
		);
	}

	public function read(): array {
		return $this->state;
	}

	public function write( array $state ): void {
		$this->state = $state;
	}
}

final class EmbedJobTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->meta = array();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( '
				class WP_Post {
					public int $ID = 0;
					public string $post_title = "";
					public string $post_excerpt = "";
					public string $post_content = "";
					public string $post_type = "post";
				}
			' );
		}

		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $h ) => trim( strip_tags( (string) $h ) )
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ (int) $post_id ][ (string) $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id = 1, string $title = 'T', string $content = 'B' ): \WP_Post {
		$p               = new \WP_Post();
		$p->ID           = $id;
		$p->post_title   = $title;
		$p->post_content = $content;
		return $p;
	}

	private function make_job( ScriptedProvider $provider, ?InMemoryState $state = null ): array {
		$rl       = new RateLimiter(
			static fn(): float => 0.0,
			static function ( float $s ): void {} // no-op sleeper
		);
		$state    = $state ?? new InMemoryState();
		$hash     = new HashDiffDetector();
		$job      = new EmbedJob( $provider, new IndexableTextBuilder(), $rl, $hash, $state );
		return array( $job, $state );
	}

	public function test_success_writes_embedding_hash_and_clears_dirty(): void {
		$provider         = new ScriptedProvider();
		$provider->script = array( 0 => array_fill( 0, 1536, 0.5 ) );

		[ $job, $state ] = $this->make_job( $provider );

		// Seed dirty flag so we can verify it gets cleared.
		$this->meta[7]['_sp_dirty'] = '1';

		$result = $job->run( $this->post( 7, 'Hello', 'Body content.' ), 1 );

		$this->assertSame( EmbedJob::OUTCOME_SUCCESS, $result['outcome'] );
		$this->assertArrayHasKey( '_sp_embedding', $this->meta[7] );
		$this->assertArrayHasKey( '_sp_text_hash', $this->meta[7] );
		$this->assertArrayNotHasKey( '_sp_dirty', $this->meta[7] );
		$this->assertSame( 1, $state->state['metrics']['succeeded'] );
	}

	public function test_retryable_attempt_one_returns_retry_with_backoff_two_seconds(): void {
		$provider         = new ScriptedProvider();
		$provider->script = array( 0 => new RetryableException( 'transient 503' ) );

		[ $job, $state ] = $this->make_job( $provider );

		$result = $job->run( $this->post( 8 ), 1 );

		$this->assertSame( EmbedJob::OUTCOME_RETRY, $result['outcome'] );
		$this->assertSame( 2, $result['retry_after_seconds'] );
		$this->assertSame( 1, $state->state['metrics']['retried'] );
		$this->assertSame( 0, $state->state['metrics']['failed'] );
		$this->assertArrayNotHasKey( '_sp_embedding', $this->meta[8] ?? array() );
	}

	public function test_retryable_attempt_two_uses_four_second_backoff(): void {
		$provider         = new ScriptedProvider();
		$provider->script = array( 0 => new RetryableException( '503 again' ) );

		[ $job, $state ] = $this->make_job( $provider );
		$result          = $job->run( $this->post( 8 ), 2 );

		$this->assertSame( EmbedJob::OUTCOME_RETRY, $result['outcome'] );
		$this->assertSame( 4, $result['retry_after_seconds'] );
	}

	public function test_retryable_attempt_three_marks_failed_no_more_retries(): void {
		$provider         = new ScriptedProvider();
		$provider->script = array( 0 => new RetryableException( '503' ) );

		[ $job, $state ] = $this->make_job( $provider );

		$result = $job->run( $this->post( 9 ), 3 );

		$this->assertSame( EmbedJob::OUTCOME_FAILED, $result['outcome'] );
		$this->assertArrayHasKey( '9', $state->state['failed_posts'] );
		$this->assertSame( 1, $state->state['metrics']['failed'] );
		$this->assertSame( 1, $state->state['metrics']['retried'] );
	}

	public function test_fatal_marks_failed_immediately_at_attempt_one(): void {
		$provider         = new ScriptedProvider();
		$provider->script = array( 0 => new FatalException( 'HTTP 401 invalid key' ) );

		[ $job, $state ] = $this->make_job( $provider );

		$result = $job->run( $this->post( 10 ), 1 );

		$this->assertSame( EmbedJob::OUTCOME_FAILED, $result['outcome'] );
		$this->assertArrayHasKey( '10', $state->state['failed_posts'] );
		$this->assertSame( 0, $state->state['metrics']['retried'], 'Fatal does not bump retry counter.' );
		$this->assertSame( 1, $state->state['metrics']['failed'] );
	}
}
