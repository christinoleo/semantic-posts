<?php
/**
 * Coverage for TB-13 additions to AjaxHandler:
 *   - handle_run_indexing_now
 *   - handle_retry_failed
 *   - handle_run_verification_now (stubbed)
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\DirtyQueue;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\TickProcessor;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Settings\AjaxHandler;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsRepository;

require_once __DIR__ . '/AjaxHandlerTest.php';
require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';

use SemanticPosts\Tests\Indexing\InMemoryState;

final class FakeTickProcessor extends TickProcessor {
	public array $next_result = array( 'processed' => 5, 'halted_for_memory' => false );
	public bool $called       = false;
	public function __construct() {} // bypass parent
	public function run(): array {
		$this->called = true;
		return $this->next_result;
	}
}

final class CapturingHashDetector extends HashDiffDetector {
	/** @var int[] */
	public array $marked_dirty = array();
	public function __construct() {} // bypass parent
	public function mark_dirty( int $post_id ): void {
		$this->marked_dirty[] = $post_id;
	}
}

final class AjaxHandlerTb13Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'update_user_meta' )->justReturn( true );
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 100 ) );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );

		Functions\when( 'wp_send_json_success' )->alias(
			static function ( $data = null ) {
				throw new AjaxResponse( true, $data, 200 );
			}
		);
		Functions\when( 'wp_send_json_error' )->alias(
			static function ( $data = null, $status = 200 ) {
				throw new AjaxResponse( false, $data, (int) $status );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build( ?FakeTickProcessor $tick = null, ?CapturingHashDetector $hash = null, ?InMemoryState $state = null ): AjaxHandler {
		$settings  = new SettingsRepository();
		$estimator = new CostEstimator();
		$storage   = new FakeApiKeyStorage();
		$validator = new FakeKeyValidator();
		$cold      = new FakeColdStart();
		$wiper     = new FakeWiper();
		$queue     = new class extends UnindexedQueue {
			public function count(): int {
				return 0;
			}
		};
		$dirty = new class extends DirtyQueue {
			public function count(): int {
				return 0;
			}
		};
		return new AjaxHandler(
			$settings,
			$estimator,
			$storage,
			$validator,
			$cold,
			$wiper,
			$queue,
			$tick,
			$dirty,
			$hash,
			$state
		);
	}

	private function capture( callable $fn ): AjaxResponse {
		try {
			$fn();
		} catch ( AjaxResponse $r ) {
			return $r;
		}
		$this->fail( 'Handler did not produce a JSON response.' );
	}

	public function test_run_indexing_now_returns_500_when_not_wired(): void {
		$h   = $this->build();
		$res = $this->capture( fn() => $h->handle_run_indexing_now() );
		$this->assertFalse( $res->success );
		$this->assertSame( 500, $res->status );
	}

	public function test_run_indexing_now_invokes_tick_processor(): void {
		$tick = new FakeTickProcessor();
		$h    = $this->build( $tick );
		$res  = $this->capture( fn() => $h->handle_run_indexing_now() );
		$this->assertTrue( $res->success );
		$this->assertTrue( $tick->called );
		$this->assertSame( 5, $res->data['tick']['processed'] );
	}

	public function test_retry_failed_returns_500_when_not_wired(): void {
		$h   = $this->build();
		$res = $this->capture( fn() => $h->handle_retry_failed() );
		$this->assertFalse( $res->success );
		$this->assertSame( 500, $res->status );
	}

	public function test_retry_failed_marks_failed_posts_dirty_and_resets_state(): void {
		$state                       = new InMemoryState();
		$state->state['failed_posts'] = array( '7' => 100, '11' => 200, '13' => 300 );
		$state->state['metrics']['failed'] = 3;
		$hash = new CapturingHashDetector();

		$h   = $this->build( null, $hash, $state );
		$res = $this->capture( fn() => $h->handle_retry_failed() );

		$this->assertTrue( $res->success );
		$this->assertSame( 3, $res->data['retried'] );
		$this->assertSame( array( 7, 11, 13 ), $hash->marked_dirty );
		$this->assertSame( array(), $state->state['failed_posts'] );
		$this->assertSame( 0, $state->state['metrics']['failed'] );
	}

	public function test_run_verification_now_returns_stub_response(): void {
		$h   = $this->build();
		$res = $this->capture( fn() => $h->handle_run_verification_now() );
		$this->assertTrue( $res->success );
		$this->assertTrue( $res->data['stubbed'] );
	}

	public function test_all_new_endpoints_require_authorization(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		$h = $this->build( new FakeTickProcessor(), new CapturingHashDetector(), new InMemoryState() );
		foreach (
			array(
				static fn() => $h->handle_run_indexing_now(),
				static fn() => $h->handle_retry_failed(),
				static fn() => $h->handle_run_verification_now(),
			) as $fn
		) {
			$res = $this->capture( $fn );
			$this->assertFalse( $res->success );
			$this->assertSame( 403, $res->status );
		}
	}
}
