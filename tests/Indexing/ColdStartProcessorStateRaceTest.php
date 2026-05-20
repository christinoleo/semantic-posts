<?php
/**
 * Coverage for the v0.1.3 fix: ColdStartProcessor.run_batch must NOT overwrite
 * metric/failed_posts mutations made by EmbedJob (record_success,
 * mark_post_failed) when it persists the cursor mid-batch.
 *
 * Before the fix, run_batch read state once at the top, mutated it in the
 * foreach, and wrote the (stale) snapshot back — clobbering the increments
 * record_success had written in between.
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

final class ColdStartProcessorStateRaceTest extends TestCase {

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
			function ( $pid, $key ) {
				return $this->meta[ (int) $pid ][ (string) $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $pid, $key, $value ) {
				$this->meta[ (int) $pid ][ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $pid, $key ) {
				unset( $this->meta[ (int) $pid ][ (string) $key ] );
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

	public function test_succeeded_counter_survives_run_batch_writes(): void {
		$queue            = new StubUnindexedQueue();
		$queue->remaining = array( 1, 2, 3 );

		$builder      = new IndexableTextBuilder();
		$rate_limiter = new RateLimiter( static fn(): float => 0.0, static function ( float $s ): void {} );
		$hash         = new HashDiffDetector( $builder );
		$state        = new InMemoryState();
		$provider     = new CountingColdProvider();
		$neighbors    = new NeighborStore();
		$crawler      = new Crawler( $neighbors, static fn() => array(), static fn() => array(), 200 );
		$embed_job    = new EmbedJob( $provider, $builder, $rate_limiter, $hash, $state, $crawler );
		$memory       = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 1024 );
		$cold         = new ColdStartProcessor( $queue, $embed_job, $crawler, $state, $memory );

		$result = $cold->run_batch();

		$this->assertSame( 3, $result['processed'] );
		// The critical assertion: succeeded counter must reflect all 3 records,
		// not 0 (which is what the stale-state-overwrite bug produced).
		$this->assertSame( 3, $state->state['metrics']['succeeded'] );
	}
}
