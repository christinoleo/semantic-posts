<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\CLI;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SemanticPosts\CLI\Commands;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\TickProcessor;
use SemanticPosts\Indexing\Wiper;
use SemanticPosts\Observability\Metrics;
use SemanticPosts\Verification\VerificationPass;

require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';
require_once __DIR__ . '/../Settings/AjaxHandlerTest.php';     // FakeColdStart, FakeWiper, etc.
require_once __DIR__ . '/../Settings/AjaxHandlerTb13Test.php'; // FakeTickProcessor, CapturingHashDetector.
require_once __DIR__ . '/../Settings/AjaxHandlerTb14Test.php'; // FakeVerification.

use SemanticPosts\Tests\Indexing\InMemoryState;
use SemanticPosts\Tests\Settings\FakeColdStart;
use SemanticPosts\Tests\Settings\FakeWiper;
use SemanticPosts\Tests\Settings\FakeTickProcessor;
use SemanticPosts\Tests\Settings\CapturingHashDetector;
use SemanticPosts\Tests\Settings\FakeVerification;

/**
 * Recording WP_CLI stub. Captures every static call so tests can assert.
 */
final class WpCliRecorder {
	/** @var array<int,array{0:string,1:mixed}> */
	public static array $events = array();

	public static function reset(): void {
		self::$events = array();
	}

	public static function success( $msg ): void {
		self::$events[] = array( 'success', $msg );
	}
	public static function error( $msg, $exit = true ): void {
		self::$events[] = array( 'error', $msg );
		if ( $exit ) {
			throw new \RuntimeException( (string) $msg );
		}
	}
	public static function warning( $msg ): void {
		self::$events[] = array( 'warning', $msg );
	}
	public static function log( $msg ): void {
		self::$events[] = array( 'log', $msg );
	}
	public static function line( $msg = '' ): void {
		self::$events[] = array( 'line', $msg );
	}
	public static function print_value( $value, $args = array() ): void {
		self::$events[] = array(
			'print_value:' . ( $args['format'] ?? 'table' ),
			$value,
		);
	}
}

final class CommandsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		WpCliRecorder::reset();

		// Install the stub WP_CLI alias only on first run.
		if ( ! class_exists( '\\WP_CLI' ) ) {
			class_alias( WpCliRecorder::class, '\\WP_CLI' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build(
		?ColdStartProcessor $cold_start = null,
		?Wiper $wiper = null,
		?TickProcessor $tick = null,
		?VerificationPass $verification = null,
		?InMemoryState $state = null,
		?HashDiffDetector $hash = null,
		?Metrics $metrics = null
	): Commands {
		return new Commands(
			$cold_start ?? new FakeColdStart(),
			$wiper ?? new FakeWiper(),
			$tick ?? new FakeTickProcessor(),
			$verification ?? new FakeVerification(),
			$state ?? new InMemoryState(),
			$hash ?? new CapturingHashDetector(),
			$metrics ?? new \SemanticPosts\Observability\NullMetrics()
		);
	}

	private function eventTypes(): array {
		return array_map( static fn( $e ) => $e[0], WpCliRecorder::$events );
	}

	public function test_index_warns_when_cold_start_inactive(): void {
		$cold        = new FakeColdStart();
		$cold->is_active_returns = false;
		$cmd = $this->build( $cold );
		$cmd->index();
		$this->assertSame( array( 'warning' ), $this->eventTypes() );
	}

	public function test_index_loops_until_processed_zero(): void {
		$cold = new IndexingColdStub( array(
			array( 'processed' => 5, 'halted_for_memory' => false ),
			array( 'processed' => 3, 'halted_for_memory' => false ),
			array( 'processed' => 0, 'halted_for_memory' => false ),
		) );

		$cmd = $this->build( $cold );
		$cmd->index();
		$types = $this->eventTypes();
		// log + log + log + success (3 batches, then success).
		$this->assertSame( array( 'log', 'log', 'log', 'success' ), $types );
		$this->assertStringContainsString( 'Indexed 8', (string) WpCliRecorder::$events[3][1] );
	}

	public function test_index_warns_on_memory_halt_and_exits(): void {
		$cold = new IndexingColdStub( array(
			array( 'processed' => 2, 'halted_for_memory' => true ),
		) );
		$cmd = $this->build( $cold );
		$cmd->index();
		$this->assertContains( 'warning', $this->eventTypes() );
	}

	public function test_reindex_wipes_then_indexes(): void {
		$wiper      = new FakeWiper();
		$wiper->rows = 12;
		$cold = new IndexingColdStub( array( array( 'processed' => 0, 'halted_for_memory' => false ) ) );
		$cold->is_active_returns = true;
		$cmd = $this->build( $cold, $wiper );
		$cmd->reindex();
		$this->assertTrue( $wiper->wiped_called );
		// Wipe log + drain log + success (success because IndexingColdStub returns 0 immediately).
		$this->assertSame( array( 'log', 'log', 'success' ), $this->eventTypes() );
	}

	public function test_process_dirty_runs_tick_and_succeeds(): void {
		$tick = new FakeTickProcessor();
		$cmd  = $this->build( null, null, $tick );
		$cmd->process_dirty();
		$this->assertTrue( $tick->called );
		$this->assertSame( array( 'success' ), $this->eventTypes() );
	}

	public function test_verify_prints_mrd_summary(): void {
		$ver = new FakeVerification();
		$ver->next_result = array(
			'mrd'       => 0.42,
			'sampled'   => 5,
			'drift'     => false,
			'threshold' => 1.5,
		);
		$cmd = $this->build( null, null, null, $ver );
		$cmd->verify();
		$this->assertTrue( $ver->called );
		$this->assertStringContainsString( 'MRD = 0.42', (string) WpCliRecorder::$events[0][1] );
	}

	public function test_retry_failed_marks_dirty_and_clears(): void {
		$state                       = new InMemoryState();
		$state->state['failed_posts'] = array( '4' => 100, '5' => 200 );
		$state->state['metrics']['failed'] = 2;
		$hash = new CapturingHashDetector();

		$cmd = $this->build( null, null, null, null, $state, $hash );
		$cmd->retry_failed();
		$this->assertSame( array( 4, 5 ), $hash->marked_dirty );
		$this->assertSame( array(), $state->state['failed_posts'] );
		$this->assertSame( 0, $state->state['metrics']['failed'] );
	}

	public function test_status_json_format_emits_print_value(): void {
		$metrics = new \SemanticPosts\Observability\NullMetrics();
		$cmd     = $this->build( null, null, null, null, null, null, $metrics );
		$cmd->status( array(), array( 'format' => 'json' ) );
		$this->assertSame( 'print_value:json', WpCliRecorder::$events[0][0] );
		$this->assertArrayHasKey( 'embedding_calls', WpCliRecorder::$events[0][1] );
	}

	public function test_status_default_format_is_yaml(): void {
		$metrics = new \SemanticPosts\Observability\NullMetrics();
		$cmd     = $this->build( null, null, null, null, null, null, $metrics );
		$cmd->status();
		$this->assertSame( 'print_value:yaml', WpCliRecorder::$events[0][0] );
	}
}

/**
 * ColdStartProcessor stub with a scripted batch sequence so we can exercise the
 * index command's loop without booting the real machinery.
 */
final class IndexingColdStub extends ColdStartProcessor {
	/** @var array<int,array{processed:int,halted_for_memory:bool}> */
	private array $batches;
	private int $cursor = 0;
	public bool $is_active_returns = true;

	public function __construct( array $batches ) {
		// Bypass parent constructor; we only need the public surface.
		$this->batches = $batches;
	}

	public function is_active(): bool {
		return $this->is_active_returns && $this->cursor < count( $this->batches );
	}

	public function run_batch(): array {
		$out = $this->batches[ $this->cursor ] ?? array( 'processed' => 0, 'halted_for_memory' => false );
		++$this->cursor;
		return $out;
	}

	public function start(): bool {
		return true;
	}

	public function progress(): array {
		return array(
			'phase'             => 'bootstrap',
			'last_processed_id' => 0,
			'indexed_count'     => 0,
			'pending_count'     => 0,
			'started_at'        => null,
			'completed_at'      => null,
		);
	}
}
