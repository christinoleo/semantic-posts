<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\Provider;
use SemanticPosts\Indexing\DirtyQueue;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\MemoryGuard;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\TickProcessor;

/** Configurable queue stub. */
final class StubQueue extends DirtyQueue {
	/** @var int[] */
	public array $batch = array();
	public int $remaining_count = 0;

	public function next_batch( int $limit = 50 ): array {
		return array_slice( $this->batch, 0, $limit );
	}

	public function count(): int {
		return $this->remaining_count;
	}
}

/** Counting provider — every embed succeeds with a fixed-dim vector. */
final class CountingProvider implements Provider {
	public int $calls = 0;
	public function name(): string {
		return 'counting';
	}
	public function maxInputTokens(): int {
		return 8192;
	}
	public function costPerMillionTokens(): float {
		return 0.0;
	}
	public function embed( string $text ): array {
		++$this->calls;
		return array_fill( 0, 1536, 0.0 );
	}
}

final class TickProcessorTest extends TestCase {

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

	private function build_tick( StubQueue $queue, MemoryGuard $memory, CountingProvider $provider ): array {
		$builder      = new IndexableTextBuilder();
		$rate_limiter = new RateLimiter( static fn(): float => 0.0, static function ( float $s ): void {} );
		$hash         = new HashDiffDetector( $builder );
		$state        = new InMemoryState();
		$embed_job    = new EmbedJob( $provider, $builder, $rate_limiter, $hash, $state );
		$tick         = new TickProcessor( $queue, $embed_job, $memory, $state );
		return array( $tick, $state );
	}

	public function test_run_processes_all_queue_items_up_to_max(): void {
		$queue        = new StubQueue();
		$queue->batch = array( 1, 2, 3, 4, 5 );
		$memory       = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 1024 );
		$provider     = new CountingProvider();

		[ $tick, $state ] = $this->build_tick( $queue, $memory, $provider );
		$result           = $tick->run();

		$this->assertSame( 5, $result['processed'] );
		$this->assertFalse( $result['halted_for_memory'] );
		$this->assertSame( 5, $provider->calls );
		$this->assertSame( 5, $state->state['metrics']['succeeded'] );
	}

	public function test_run_halts_when_memory_threshold_crossed_mid_batch(): void {
		$queue        = new StubQueue();
		$queue->batch = array( 1, 2, 3, 4, 5 );

		$call = 0;
		$mem  = new MemoryGuard(
			256 * 1024 * 1024,
			static function () use ( &$call ): int {
				++$call;
				// First two checks under threshold, third over.
				return $call < 3 ? 1024 : (int) ( 0.81 * 256 * 1024 * 1024 );
			}
		);
		$provider = new CountingProvider();

		[ $tick, $state ] = $this->build_tick( $queue, $mem, $provider );
		$result           = $tick->run();

		$this->assertTrue( $result['halted_for_memory'] );
		$this->assertLessThan( 5, $result['processed'] );
	}

	public function test_run_halts_before_starting_when_memory_already_high(): void {
		$queue        = new StubQueue();
		$queue->batch = array( 1, 2, 3 );
		$memory       = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 256 * 1024 * 1024 );
		$provider     = new CountingProvider();

		[ $tick, ] = $this->build_tick( $queue, $memory, $provider );
		$result    = $tick->run();

		$this->assertSame( 0, $result['processed'] );
		$this->assertTrue( $result['halted_for_memory'] );
		$this->assertSame( 0, $provider->calls );
	}

	public function test_run_persists_dirty_queue_count_in_state(): void {
		$queue                  = new StubQueue();
		$queue->batch           = array( 1 );
		$queue->remaining_count = 42;
		$memory                 = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 1024 );
		$provider               = new CountingProvider();

		[ $tick, $state ] = $this->build_tick( $queue, $memory, $provider );
		$tick->run();

		$this->assertSame( 42, $state->state['dirty_queue_count'] );
	}
}
