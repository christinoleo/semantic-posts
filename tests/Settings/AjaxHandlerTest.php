<?php
/**
 * Coverage for TB-12 AjaxHandler — nonce + cap boundary (AR-12), payload
 * sanitisation, and the success/error shapes the admin JS expects.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Indexing\Wiper;
use SemanticPosts\Security\ApiKeyStorage;
use SemanticPosts\Security\ApiKeyValidator;
use SemanticPosts\Settings\AjaxHandler;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsRepository;

/**
 * Sentinel thrown by stub wp_send_json_* so handlers exit deterministically
 * without invoking PHPUnit's `wp_die`.
 */
final class AjaxResponse extends \Exception {
	public bool $success;
	/** @var mixed */
	public $data;
	public int $status;
	public function __construct( bool $success, $data, int $status = 200 ) {
		parent::__construct( $success ? 'success' : 'error' );
		$this->success = $success;
		$this->data    = $data;
		$this->status  = $status;
	}
}

final class FakeColdStart extends ColdStartProcessor {
	public bool $started_called    = false;
	public bool $start_returns     = true;
	public bool $is_active_returns = true;
	public array $progress_returns = array(
		'phase'             => 'idle',
		'last_processed_id' => 0,
		'indexed_count'     => 0,
		'pending_count'     => 0,
		'started_at'        => null,
		'completed_at'      => null,
	);
	public function __construct() {
		// intentionally do NOT call parent::__construct — we only test surface.
	}
	public function start(): bool {
		$this->started_called = true;
		return $this->start_returns;
	}
	public function progress(): array {
		return $this->progress_returns;
	}
	public function is_active(): bool {
		return $this->is_active_returns;
	}
	public function run_batch(): array {
		return array( 'processed' => 0, 'halted_for_memory' => false );
	}
}

final class FakeWiper extends Wiper {
	public bool $wiped_called = false;
	public int $rows          = 0;
	public function __construct() {} // bypass parent
	public function wipe_embeddings(): int {
		$this->wiped_called = true;
		return $this->rows;
	}
}

final class FakeApiKeyStorage extends ApiKeyStorage {
	public ?string $stored = null;
	public function store( string $api_key ): void {
		$this->stored = $api_key;
	}
	public function retrieve(): ?string {
		return $this->stored;
	}
}

final class FakeKeyValidator extends ApiKeyValidator {
	public array $next_result = array(
		'ok'         => true,
		'error_code' => 'ok',
		'message'    => 'ok',
	);
	public function validate( string $api_key ): array {
		return $this->next_result;
	}
}

final class AjaxHandlerTest extends TestCase {

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
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'wp_count_posts' )->justReturn( (object) array( 'publish' => 250 ) );

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

	private function build(): AjaxHandler {
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
		return new AjaxHandler( $settings, $estimator, $storage, $validator, $cold, $wiper, $queue );
	}

	private function capture( callable $fn ): AjaxResponse {
		try {
			$fn();
		} catch ( AjaxResponse $r ) {
			return $r;
		}
		$this->fail( 'Handler did not produce a JSON response.' );
	}

	public function test_unauth_request_returns_403(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$h   = $this->build();
		$res = $this->capture( static fn() => $h->handle_progress() );
		$this->assertFalse( $res->success );
		$this->assertSame( 403, $res->status );
	}

	public function test_bad_nonce_returns_403(): void {
		Functions\when( 'check_ajax_referer' )->justReturn( false );
		$h   = $this->build();
		$res = $this->capture( static fn() => $h->handle_progress() );
		$this->assertFalse( $res->success );
		$this->assertSame( 403, $res->status );
	}

	public function test_cost_preview_returns_estimator_output(): void {
		$_POST['model'] = SettingsRepository::MODEL_LARGE;
		$h              = $this->build();
		$res            = $this->capture( fn() => $h->handle_cost_preview() );
		$this->assertTrue( $res->success );
		$this->assertSame( SettingsRepository::MODEL_LARGE, $res->data['model'] );
		$this->assertSame( 250, $res->data['posts'] );
		// 250 × 500 = 125000 tokens × 0.130/1M = $0.01625
		$this->assertEqualsWithDelta( 0.01625, $res->data['estimated_usd'], 0.0001 );
	}

	public function test_validate_api_key_stores_on_success(): void {
		$_POST['api_key'] = 'sk-test-valid';
		$h                = $this->build();
		$res              = $this->capture( fn() => $h->handle_validate_api_key() );
		$this->assertTrue( $res->success );
		$this->assertSame( 'sk-t*****alid', $res->data['masked'] );

		$reflection = new \ReflectionClass( $h );
		$prop       = $reflection->getProperty( 'key_storage' );
		$prop->setAccessible( true );
		$this->assertSame( 'sk-test-valid', $prop->getValue( $h )->stored );
	}

	public function test_validate_api_key_rejects_on_validator_failure(): void {
		$_POST['api_key'] = 'sk-bad';
		$h                = $this->build();

		// Override validator response.
		$reflection = new \ReflectionClass( $h );
		$prop       = $reflection->getProperty( 'key_validator' );
		$prop->setAccessible( true );
		$validator  = $prop->getValue( $h );
		$validator->next_result = array(
			'ok'         => false,
			'error_code' => 'unauthorized',
			'message'    => 'OpenAI rejected the API key.',
		);

		$res = $this->capture( fn() => $h->handle_validate_api_key() );
		$this->assertFalse( $res->success );
		$this->assertSame( 400, $res->status );
	}

	public function test_start_indexing_requires_api_key(): void {
		$h   = $this->build(); // FakeApiKeyStorage::stored is null.
		$res = $this->capture( fn() => $h->handle_start_indexing() );
		$this->assertFalse( $res->success );
		$this->assertSame( 400, $res->status );
	}

	public function test_start_indexing_delegates_to_cold_start_when_key_present(): void {
		$h = $this->build();
		$reflection = new \ReflectionClass( $h );
		$prop       = $reflection->getProperty( 'key_storage' );
		$prop->setAccessible( true );
		$storage    = $prop->getValue( $h );
		$storage->stored = 'sk-installed';

		$res = $this->capture( fn() => $h->handle_start_indexing() );
		$this->assertTrue( $res->success );

		$cold_prop = $reflection->getProperty( 'cold_start' );
		$cold_prop->setAccessible( true );
		$cold      = $cold_prop->getValue( $h );
		$this->assertTrue( $cold->started_called );
	}

	public function test_wipe_and_reindex_requires_api_key(): void {
		$h   = $this->build();
		$res = $this->capture( fn() => $h->handle_wipe_and_reindex() );
		$this->assertFalse( $res->success );
		$this->assertSame( 400, $res->status );
	}

	public function test_wipe_and_reindex_calls_wiper_then_cold_start(): void {
		$h = $this->build();
		$reflection = new \ReflectionClass( $h );
		$prop       = $reflection->getProperty( 'key_storage' );
		$prop->setAccessible( true );
		$storage    = $prop->getValue( $h );
		$storage->stored = 'sk-installed';

		$wiper_prop = $reflection->getProperty( 'wiper' );
		$wiper_prop->setAccessible( true );
		$wiper      = $wiper_prop->getValue( $h );
		$wiper->rows = 99;

		$res = $this->capture( fn() => $h->handle_wipe_and_reindex() );
		$this->assertTrue( $res->success );
		$this->assertTrue( $wiper->wiped_called );
		$this->assertSame( 99, $res->data['rows_deleted'] );
	}

	public function test_progress_returns_cold_start_snapshot(): void {
		$h    = $this->build();
		$res  = $this->capture( fn() => $h->handle_progress() );
		$this->assertTrue( $res->success );
		$this->assertArrayHasKey( 'phase', $res->data );
		$this->assertArrayHasKey( 'pending_count', $res->data );
	}

	public function test_dismiss_floor_notice_writes_user_meta(): void {
		$captured = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $value ) use ( &$captured ) {
				$captured = array( $uid, $key, $value );
				return true;
			}
		);
		$h   = $this->build();
		$res = $this->capture( fn() => $h->handle_dismiss_floor_notice() );
		$this->assertTrue( $res->success );
		$this->assertSame( 1, $captured[0] );
		$this->assertSame( AjaxHandler::NOTICE_USER_META_KEY, $captured[1] );
	}
}
