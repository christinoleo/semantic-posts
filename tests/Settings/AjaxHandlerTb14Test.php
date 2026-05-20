<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Indexing\DirtyQueue;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Settings\AjaxHandler;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsRepository;
use SemanticPosts\Verification\VerificationPass;

require_once __DIR__ . '/AjaxHandlerTest.php';
require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';

use SemanticPosts\Tests\Indexing\InMemoryState;

final class FakeVerification extends VerificationPass {
	public bool $called = false;
	public array $next_result = array(
		'mrd'       => 0.42,
		'sampled'   => 5,
		'drift'     => false,
		'threshold' => 1.5,
	);
	public function __construct() {} // bypass parent
	public function run(): array {
		$this->called = true;
		return $this->next_result;
	}
	public function is_due(): bool {
		return true;
	}
	public function threshold(): float {
		return 1.5;
	}
}

final class AjaxHandlerTb14Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();

		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 0 ) );
		Functions\when( 'get_option' )->justReturn( array() );

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

	private function build( ?FakeVerification $verification ): AjaxHandler {
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
			null,
			$dirty,
			null,
			new InMemoryState(),
			$verification
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

	public function test_run_verification_now_invokes_verification(): void {
		$ver = new FakeVerification();
		$h   = $this->build( $ver );
		$res = $this->capture( fn() => $h->handle_run_verification_now() );
		$this->assertTrue( $res->success );
		$this->assertTrue( $ver->called );
		$this->assertSame( 0.42, $res->data['result']['mrd'] );
	}

	public function test_run_verification_now_requires_auth(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$h   = $this->build( new FakeVerification() );
		$res = $this->capture( fn() => $h->handle_run_verification_now() );
		$this->assertFalse( $res->success );
		$this->assertSame( 403, $res->status );
	}
}
