<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\Provider;
use SemanticPosts\Indexing\CleanupRouter;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\SavePostHandler;
use SemanticPosts\Indexing\StateRepository;

/** Provider that records calls instead of HTTPing. */
final class HandlerDummyProvider implements Provider {
	public int $calls = 0;
	public function name(): string {
		return 'dummy';
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

final class SavePostHandlerTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<int,array<int,array{0:int,1:string,2:array<int,mixed>}>> */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta      = array();
		$this->scheduled = array();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_title = ""; public string $post_excerpt = ""; public string $post_content = ""; public string $post_status = "publish"; public string $post_password = ""; public string $post_type = "post"; }' );
		}

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $h ) => trim( strip_tags( (string) $h ) ) );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
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
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $ts, $hook, $args ) {
				$this->scheduled[] = array( $ts, $hook, $args );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build_handler(): SavePostHandler {
		$builder      = new IndexableTextBuilder();
		$rate_limiter = new RateLimiter( static fn(): float => 0.0, static function ( float $s ): void {} );
		$hash         = new HashDiffDetector( $builder );
		$state        = new InMemoryState();
		$provider     = new HandlerDummyProvider();
		$embed_job    = new EmbedJob( $provider, $builder, $rate_limiter, $hash, $state );
		$cleanup      = new CleanupRouter( new NeighborStore(), $hash );
		return new SavePostHandler( $hash, $cleanup, $embed_job );
	}

	private function post( int $id, string $title = 'T', string $content = 'B', string $status = 'publish', string $password = '' ): \WP_Post {
		$p                = new \WP_Post();
		$p->ID            = $id;
		$p->post_title    = $title;
		$p->post_content  = $content;
		$p->post_status   = $status;
		$p->post_password = $password;
		return $p;
	}

	public function test_save_post_autosave_short_circuits(): void {
		Functions\when( 'wp_is_post_autosave' )->justReturn( true );

		$this->build_handler()->on_save_post( 1, $this->post( 1 ) );

		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_save_post_revision_short_circuits(): void {
		Functions\when( 'wp_is_post_revision' )->justReturn( 999 );

		$this->build_handler()->on_save_post( 1, $this->post( 1 ) );

		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_save_post_non_publish_does_not_detect(): void {
		$this->build_handler()->on_save_post( 1, $this->post( 1, 'T', 'B', 'draft' ) );
		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_save_post_password_protected_does_not_detect(): void {
		$this->build_handler()->on_save_post( 1, $this->post( 1, 'T', 'B', 'publish', 'secret' ) );
		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_save_post_publish_no_password_calls_detect_and_writes_dirty(): void {
		$this->build_handler()->on_save_post( 1, $this->post( 1 ) );
		$this->assertArrayHasKey( '_sp_text_hash', $this->meta[1] );
		$this->assertSame( 1, $this->meta[1]['_sp_dirty'] );
	}

	public function test_transition_to_publish_no_prior_embedding_schedules_immediate_embed(): void {
		// No prior _sp_embedding.
		$this->build_handler()->on_transition_post_status( 'publish', 'draft', $this->post( 1 ) );

		$this->assertCount( 1, $this->scheduled );
		$this->assertSame( SavePostHandler::IMMEDIATE_EMBED_HOOK, $this->scheduled[0][1] );
		$this->assertSame( array( 1 ), $this->scheduled[0][2] );
	}

	public function test_transition_to_publish_with_prior_embedding_does_not_reschedule(): void {
		$this->meta[1]['_sp_embedding'] = 'existing';
		$this->build_handler()->on_transition_post_status( 'publish', 'draft', $this->post( 1 ) );
		$this->assertCount( 0, $this->scheduled );
	}

	public function test_transition_away_from_publish_triggers_cleanup(): void {
		$this->meta[1]['_sp_embedding'] = 'existing';
		$this->meta[1]['_sp_text_hash'] = 'abc';
		$this->build_handler()->on_transition_post_status( 'draft', 'publish', $this->post( 1, 'T', 'B', 'draft' ) );

		$this->assertArrayNotHasKey( '_sp_embedding', $this->meta[1] ?? array() );
		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_trash_triggers_cleanup(): void {
		$this->meta[1]['_sp_embedding'] = 'existing';
		$this->meta[1]['_sp_text_hash'] = 'abc';
		$this->build_handler()->on_trash( 1 );

		$this->assertArrayNotHasKey( '_sp_embedding', $this->meta[1] ?? array() );
		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] ?? array() );
	}

	public function test_immediate_embed_callback_runs_embed_job_for_post(): void {
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->post( (int) $id, 'T', 'B', 'publish' );
			}
		);

		$handler = $this->build_handler();
		$handler->handle_immediate_embed( 1 );

		// EmbedJob success path writes _sp_embedding.
		$this->assertArrayHasKey( '_sp_embedding', $this->meta[1] );
	}

	public function test_immediate_embed_callback_skips_non_publish(): void {
		Functions\when( 'get_post' )->alias(
			function ( $id ) {
				return $this->post( (int) $id, 'T', 'B', 'draft' );
			}
		);

		$handler = $this->build_handler();
		$handler->handle_immediate_embed( 1 );

		$this->assertArrayNotHasKey( '_sp_embedding', $this->meta[1] ?? array() );
	}
}
