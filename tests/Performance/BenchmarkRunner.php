<?php
/**
 * Benchmark runner — emits the JSON payload defined in
 * `docs/benchmark-schema.md` and applies the regression-gate verdicts.
 *
 * Composition over inheritance: each NFR is owned by a separate measurement
 * callable injected at construction. Tests pin the JSON shape + regression
 * logic; the CI workflow wires real measurement closures.
 *
 * Schema version policy:
 *   - Additive changes (new keys inside `results.X`): keep version.
 *   - Breaking changes (renamed/removed keys): bump `SCHEMA_VERSION`.
 *
 * @package SemanticPosts\Tests\Performance
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Performance;

final class BenchmarkRunner {

	public const SCHEMA_VERSION = '1';

	/** Regression gates per NFR — exceeded = workflow fail. */
	public const GATE_TTFB_MS_MAX     = 5;   // NFR-PERF-1.
	public const GATE_QUERIES_MAX     = 2;   // NFR-PERF-2.
	public const GATE_HTTP_CALLS_MAX  = 0;   // NFR-PERF-3.
	public const GATE_MEMORY_MB_MAX   = 1.0; // NFR-PERF-4.
	public const GATE_COLD_START_MS_MAX = 180_000; // NFR-IDX-1: 3 minutes / 5k posts.

	/** @var array<string,callable():array<string,mixed>> */
	private array $measurements;

	/** @var callable():int */
	private $clock;

	/** @var string */
	private string $environment;

	/** @var string */
	private string $commit_sha;

	/**
	 * @param array<string,callable():array<string,mixed>> $measurements Keyed by NFR id (NFR-PERF-1 etc.) → closure returning the measurement dict.
	 * @param callable                                     $clock        () => int. Unix seconds for `timestamp`.
	 * @param string                                       $environment  Free-form label, e.g. "ubuntu-24.04 / php8.0 / mysql8.0 / redis-off".
	 * @param string                                       $commit_sha   Git SHA of the run.
	 */
	public function __construct(
		array $measurements,
		callable $clock,
		string $environment,
		string $commit_sha
	) {
		$this->measurements = $measurements;
		$this->clock        = $clock;
		$this->environment  = $environment;
		$this->commit_sha   = $commit_sha;
	}

	/**
	 * Execute every measurement and assemble the canonical JSON payload.
	 *
	 * @return array<string,mixed>
	 */
	public function run(): array {
		$results = array();
		foreach ( $this->measurements as $nfr_id => $closure ) {
			$results[ $nfr_id ] = $closure();
		}

		$verdicts = array(
			'NFR-PERF-1' => $this->verdict_ttfb( $results ),
			'NFR-PERF-2' => $this->verdict_queries( $results ),
			'NFR-PERF-3' => $this->verdict_http( $results ),
			'NFR-PERF-4' => $this->verdict_memory( $results ),
			'NFR-IDX-1'  => $this->verdict_cold_start( $results ),
		);

		$failed = array_filter( $verdicts, static fn( $v ): bool => 'fail' === $v );

		return array(
			'version'     => self::SCHEMA_VERSION,
			'timestamp'   => ( $this->clock )(),
			'commit_sha'  => $this->commit_sha,
			'environment' => $this->environment,
			'results'     => $results,
			'verdicts'    => $verdicts,
			'passed'      => 0 === count( $failed ),
		);
	}

	/**
	 * Encode the payload as JSON. Pretty-printed when running under CI so the
	 * raw artifact is human-readable, compact otherwise.
	 *
	 * @param array<string,mixed> $payload Output of run().
	 */
	public static function to_json( array $payload, bool $pretty = true ): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
		if ( $pretty ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		$encoded = json_encode( $payload, $flags );
		return false === $encoded ? '{}' : $encoded;
	}

	/**
	 * @param array<string,mixed> $results Output of run() — "ttfb_delta_ms".
	 */
	private function verdict_ttfb( array $results ): string {
		$value = isset( $results['NFR-PERF-1']['ttfb_delta_ms'] ) ? (float) $results['NFR-PERF-1']['ttfb_delta_ms'] : 0.0;
		return $value > self::GATE_TTFB_MS_MAX ? 'fail' : 'pass';
	}

	/**
	 * @param array<string,mixed> $results Output of run().
	 */
	private function verdict_queries( array $results ): string {
		$value = isset( $results['NFR-PERF-2']['queries_added'] ) ? (int) $results['NFR-PERF-2']['queries_added'] : 0;
		return $value > self::GATE_QUERIES_MAX ? 'fail' : 'pass';
	}

	/**
	 * @param array<string,mixed> $results Output of run().
	 */
	private function verdict_http( array $results ): string {
		$value = isset( $results['NFR-PERF-3']['http_calls'] ) ? (int) $results['NFR-PERF-3']['http_calls'] : 0;
		return $value > self::GATE_HTTP_CALLS_MAX ? 'fail' : 'pass';
	}

	/**
	 * @param array<string,mixed> $results Output of run().
	 */
	private function verdict_memory( array $results ): string {
		$value = isset( $results['NFR-PERF-4']['memory_added_mb'] ) ? (float) $results['NFR-PERF-4']['memory_added_mb'] : 0.0;
		return $value > self::GATE_MEMORY_MB_MAX ? 'fail' : 'pass';
	}

	/**
	 * @param array<string,mixed> $results Output of run().
	 */
	private function verdict_cold_start( array $results ): string {
		$value = isset( $results['NFR-IDX-1']['cold_start_ms'] ) ? (int) $results['NFR-IDX-1']['cold_start_ms'] : 0;
		return $value > self::GATE_COLD_START_MS_MAX ? 'fail' : 'pass';
	}
}
