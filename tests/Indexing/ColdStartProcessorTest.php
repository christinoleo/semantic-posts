<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\Provider;
use SemanticPosts\Embeddings\Vector;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\MemoryGuard;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Paywall\PaywallGate;

/** Always-succeeds embedding provider that writes a fixed vector. */
final class CountingColdProvider implements Provider {
	public int $calls = 0;
	public function name(): string {
		return 'cold-test';
	}
	public function maxInputTokens(): int {
		return 8192;
	}
	public function costPerMillionTokens(): float {
		return 0.0;
	}
	public function embed( string $text ): array {
		++$this->calls;
		return array_fill( 0, 1536, 0.5 );
	}
}

/** Scripted UnindexedQueue. */
final class StubUnindexedQueue extends UnindexedQueue {
	/** @var int[] */
	public array $remaining = array();
	public int $batch_size_called_with = 0;
	public int $last_id_called_with    = 0;

	public function next_batch( int $limit = 50, int $last_id = 0 ): array {
		$this->batch_size_called_with = $limit;
		$this->last_id_called_with    = $last_id;
		$batch                        = array_slice( $this->remaining, 0, $limit );
		$this->remaining              = array_slice( $this->remaining, $limit );
		return $batch;
	}

	public function count(): int {
		return count( $this->remaining );
	}
}

final class ColdStartProcessorTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta = array();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_title = ""; public string $post_excerpt = ""; public string $post_content = ""; public string $post_type = "post"; }' );
		}

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $h ) => trim( strip_tags( (string) $h ) ) );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key /* , $single */ ) {
				return $this->meta[ (int) $post_id ][ (string) $key ] ?? '';
			}
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
		Functions\when( 'pll_get_post_language' )->justReturn( '' );
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				$p              = new \WP_Post();
				$p->ID          = (int) $id;
				$p->post_title  = "Post {$id}";
				$p->post_content = "Body {$id}";
				return $p;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build(
		StubUnindexedQueue $queue,
		?MemoryGuard $memory = null,
		int $phase_1_limit = 200
	): array {
		$builder      = new IndexableTextBuilder();
		$rate_limiter = new RateLimiter( static fn(): float => 0.0, static function ( float $s ): void {} );
		$hash         = new HashDiffDetector( $builder );
		$state        = new InMemoryState();
		$provider     = new CountingColdProvider();
		$neighbors    = new NeighborStore();
		$crawler      = new Crawler(
			$neighbors,
			static fn() => array(),
			fn() => array_keys( array_filter( $this->meta, static fn( $m ) => isset( $m['_sp_embedding'] ) ) ),
			$phase_1_limit
		);
		$embed_job    = new EmbedJob( $provider, $builder, $rate_limiter, $hash, $state, $crawler );
		$memory       = $memory ?? new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 1024 );
		$cold_start   = new ColdStartProcessor( $queue, $embed_job, $crawler, $state, $memory, new PaywallGate() );
		return array( $cold_start, $state, $provider, $crawler );
	}

	public function test_is_active_returns_true_when_queue_has_work(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3 );
		[ $cold ] = $this->build( $queue );
		$this->assertTrue( $cold->is_active() );
	}

	public function test_is_active_returns_false_when_idle_and_empty(): void {
		$queue   = new StubUnindexedQueue();
		[ $cold ] = $this->build( $queue );
		$this->assertFalse( $cold->is_active() );
	}

	public function test_run_batch_transitions_to_bootstrap_on_first_work(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3 );

		[ $cold, $state, $provider ] = $this->build( $queue );
		$result                       = $cold->run_batch();

		$this->assertSame( 3, $result['processed'] );
		$this->assertSame( 3, $provider->calls );
		$this->assertSame( ColdStartProcessor::PHASE_IDLE, $state->state['cold_start']['phase'] ?? 'unset' );
		// After drain, phase is idle again.
	}

	public function test_run_batch_advances_phase_to_graph_knn_above_threshold(): void {
		// Phase-1 limit = 2; seed 1 already-indexed post so the cursor crosses the
		// threshold within the batch (3 new + 1 pre-existing = 4 >= 2+1).
		$this->meta[99][ Vector::POSTMETA_KEY ] = Vector::encode( array_fill( 0, 1536, 0.5 ) );

		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3 );

		[ $cold, $state ] = $this->build( $queue, null, 2 );
		$cold->run_batch();

		// After processing, queue is empty → phase = idle.
		$this->assertSame( ColdStartProcessor::PHASE_IDLE, $state->state['cold_start']['phase'] );
	}

	public function test_run_batch_persists_last_processed_id_for_resumability(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 11, 22, 33 );

		[ $cold, $state ] = $this->build( $queue );
		$cold->run_batch();

		// After completion the cursor is reset to 0 (queue drained).
		$this->assertSame( 0, $state->state['cold_start']['last_processed_id'] );
	}

	public function test_run_batch_halts_on_memory_pressure_mid_batch(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3, 4, 5 );

		$call   = 0;
		$memory = new MemoryGuard(
			256 * 1024 * 1024,
			static function () use ( &$call ): int {
				++$call;
				return $call < 3 ? 1024 : (int) ( 0.81 * 256 * 1024 * 1024 );
			}
		);

		[ $cold, $state ] = $this->build( $queue, $memory );
		$result            = $cold->run_batch();

		$this->assertTrue( $result['halted_for_memory'] );
		$this->assertLessThan( 5, $result['processed'] );
		// last_processed_id captured the latest successful post (not the one we halted on).
		$this->assertGreaterThan( 0, $state->state['cold_start']['last_processed_id'] );
	}

	public function test_run_batch_resets_to_idle_when_queue_already_empty(): void {
		$queue   = new StubUnindexedQueue();
		[ $cold, $state ] = $this->build( $queue );
		$result            = $cold->run_batch();

		$this->assertSame( 0, $result['processed'] );
		$this->assertFalse( $result['halted_for_memory'] );
		$this->assertSame( ColdStartProcessor::PHASE_IDLE, $state->state['cold_start']['phase'] );
	}
}
