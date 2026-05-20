<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Performance;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BenchmarkRunner.php';

final class BenchmarkRunnerTest extends TestCase {

	private function runner( array $measurements ): BenchmarkRunner {
		return new BenchmarkRunner(
			$measurements,
			static fn(): int => 1_700_000_000,
			'test-env',
			'abc123def'
		);
	}

	private function passing_measurements(): array {
		return array(
			'NFR-PERF-1' => static fn() => array( 'ttfb_delta_ms'   => 2.0 ),
			'NFR-PERF-2' => static fn() => array( 'queries_added'   => 1 ),
			'NFR-PERF-3' => static fn() => array( 'http_calls'      => 0 ),
			'NFR-PERF-4' => static fn() => array( 'memory_added_mb' => 0.3 ),
			'NFR-IDX-1'  => static fn() => array( 'cold_start_ms'   => 90_000 ),
		);
	}

	public function test_payload_uses_schema_version_and_metadata(): void {
		$payload = $this->runner( $this->passing_measurements() )->run();
		$this->assertSame( BenchmarkRunner::SCHEMA_VERSION, $payload['version'] );
		$this->assertSame( 1_700_000_000, $payload['timestamp'] );
		$this->assertSame( 'test-env', $payload['environment'] );
		$this->assertSame( 'abc123def', $payload['commit_sha'] );
		$this->assertTrue( $payload['passed'] );
	}

	public function test_all_passing_yields_passed_true(): void {
		$payload = $this->runner( $this->passing_measurements() )->run();
		$this->assertTrue( $payload['passed'] );
		foreach ( $payload['verdicts'] as $verdict ) {
			$this->assertSame( 'pass', $verdict );
		}
	}

	public function test_ttfb_above_gate_fails(): void {
		$m                = $this->passing_measurements();
		$m['NFR-PERF-1'] = static fn() => array( 'ttfb_delta_ms' => 5.1 );
		$payload          = $this->runner( $m )->run();
		$this->assertFalse( $payload['passed'] );
		$this->assertSame( 'fail', $payload['verdicts']['NFR-PERF-1'] );
	}

	public function test_queries_above_gate_fails(): void {
		$m                = $this->passing_measurements();
		$m['NFR-PERF-2'] = static fn() => array( 'queries_added' => 3 );
		$payload          = $this->runner( $m )->run();
		$this->assertSame( 'fail', $payload['verdicts']['NFR-PERF-2'] );
	}

	public function test_http_must_be_exactly_zero(): void {
		$m                = $this->passing_measurements();
		$m['NFR-PERF-3'] = static fn() => array( 'http_calls' => 1 );
		$payload          = $this->runner( $m )->run();
		$this->assertSame( 'fail', $payload['verdicts']['NFR-PERF-3'] );
	}

	public function test_memory_above_one_mb_fails(): void {
		$m                = $this->passing_measurements();
		$m['NFR-PERF-4'] = static fn() => array( 'memory_added_mb' => 1.1 );
		$payload          = $this->runner( $m )->run();
		$this->assertSame( 'fail', $payload['verdicts']['NFR-PERF-4'] );
	}

	public function test_cold_start_over_180s_fails(): void {
		$m              = $this->passing_measurements();
		$m['NFR-IDX-1'] = static fn() => array( 'cold_start_ms' => 200_000 );
		$payload        = $this->runner( $m )->run();
		$this->assertSame( 'fail', $payload['verdicts']['NFR-IDX-1'] );
	}

	public function test_to_json_round_trips(): void {
		$payload = $this->runner( $this->passing_measurements() )->run();
		$json    = BenchmarkRunner::to_json( $payload );
		$this->assertJson( $json );
		$decoded = json_decode( $json, true );
		$this->assertSame( $payload['version'], $decoded['version'] );
		$this->assertArrayHasKey( 'results', $decoded );
		$this->assertArrayHasKey( 'verdicts', $decoded );
	}

	public function test_to_json_supports_compact_output(): void {
		$payload = $this->runner( $this->passing_measurements() )->run();
		$pretty  = BenchmarkRunner::to_json( $payload, true );
		$compact = BenchmarkRunner::to_json( $payload, false );
		$this->assertLessThan( strlen( $pretty ), strlen( $compact ) );
	}
}
