<?php
/**
 * Coverage for TB-12 — ColdStartProcessor::start() + progress().
 *
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
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\MemoryGuard;
use SemanticPosts\Indexing\RateLimiter;

require_once __DIR__ . '/ColdStartProcessorTest.php';

final class ColdStartProcessorStartTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<int,array{0:int,1:string,2:array<int,mixed>}> */
	public static array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta            = array();
		self::$scheduled       = array();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_title = ""; public string $post_excerpt = ""; public string $post_content = ""; public string $post_type = "post"; }' );
		}

		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $when, $hook, $args = array() ) {
				ColdStartProcessorStartTest::$scheduled[] = array( (int) $when, (string) $hook, (array) $args );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build( StubUnindexedQueue $queue ): array {
		$builder      = new IndexableTextBuilder();
		$rate_limiter = new RateLimiter( static fn(): float => 0.0, static function ( float $s ): void {} );
		$hash         = new HashDiffDetector( $builder );
		$state        = new InMemoryState();
		$provider     = new CountingColdProvider();
		$neighbors    = new NeighborStore();
		$crawler      = new Crawler(
			$neighbors,
			static fn() => array(),
			static fn() => array(),
			200
		);
		$embed_job    = new EmbedJob( $provider, $builder, $rate_limiter, $hash, $state, $crawler );
		$memory       = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 1024 );
		$cold_start   = new ColdStartProcessor( $queue, $embed_job, $crawler, $state, $memory );
		return array( $cold_start, $state );
	}

	public function test_start_returns_false_when_queue_empty(): void {
		$queue = new StubUnindexedQueue();
		[ $cold ] = $this->build( $queue );
		$this->assertFalse( $cold->start() );
		$this->assertSame( array(), self::$scheduled );
	}

	public function test_start_transitions_phase_and_schedules_tick(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3 );

		[ $cold, $state ] = $this->build( $queue );
		$result            = $cold->start();

		$this->assertTrue( $result );
		$this->assertSame( ColdStartProcessor::PHASE_BOOTSTRAP, $state->state['cold_start']['phase'] );
		$this->assertSame( 0, $state->state['cold_start']['last_processed_id'] );
		$this->assertCount( 1, self::$scheduled );
		$this->assertSame( 'semantic_posts_cron_tick', self::$scheduled[0][1] );
	}

	public function test_start_clears_completed_timestamp_so_ui_does_not_show_stale(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1 );

		[ $cold, $state ] = $this->build( $queue );
		$state->write(
			array(
				'cold_start' => array(
					'phase'             => ColdStartProcessor::PHASE_IDLE,
					'last_processed_id' => 99,
					'completed'         => 12345,
				),
			)
		);

		$cold->start();

		$this->assertArrayNotHasKey( 'completed', $state->state['cold_start'] );
	}

	public function test_progress_returns_full_snapshot(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3, 4, 5 );

		[ $cold, $state ] = $this->build( $queue );
		$state->write(
			array(
				'cold_start' => array(
					'phase'             => ColdStartProcessor::PHASE_BOOTSTRAP,
					'last_processed_id' => 42,
					'started'           => 12345,
				),
			)
		);

		$snapshot = $cold->progress();
		$this->assertSame( ColdStartProcessor::PHASE_BOOTSTRAP, $snapshot['phase'] );
		$this->assertSame( 42, $snapshot['last_processed_id'] );
		$this->assertSame( 5, $snapshot['pending_count'] );
		$this->assertSame( 12345, $snapshot['started_at'] );
		$this->assertNull( $snapshot['completed_at'] );
	}
}
