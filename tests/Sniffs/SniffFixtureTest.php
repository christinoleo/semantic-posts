<?php
/**
 * End-to-end tests for the AR-10 / AR-12 custom PHPCS sniffs.
 *
 * Shells out to the project's `phpcs` binary against fixture files under
 * `tests/Sniffs/fixtures/`. Each fixture intentionally violates one sniff so we
 * can verify the corresponding error code is raised.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Sniffs;

use PHPUnit\Framework\TestCase;

final class SniffFixtureTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function fixture_provider(): array {
		return array(
			'AR-10 wrong owner'  => array(
				__DIR__ . '/fixtures/ar10-wrong-owner.php',
				'SemanticPosts.PostMeta.SingleWriter.WrongOwner',
			),
			'AR-10 unknown key'  => array(
				__DIR__ . '/fixtures/ar10-unknown-key.php',
				'SemanticPosts.PostMeta.SingleWriter.UnknownKey',
			),
			'AR-12 missing nonce + cap' => array(
				__DIR__ . '/fixtures/ar12-missing-checks.php',
				'SemanticPosts.Ajax.NonceCapBoundary.MissingBoundaryCheck',
			),
		);
	}

	/**
	 * @dataProvider fixture_provider
	 */
	public function test_fixture_raises_expected_error( string $fixture, string $expected_code ): void {
		$this->assertFileExists( $fixture );

		$phpcs    = dirname( __DIR__, 2 ) . '/vendor/bin/phpcs';
		$standard = dirname( __DIR__, 2 ) . '/tools/phpcs-sniffs/SemanticPosts';

		if ( ! file_exists( $phpcs ) ) {
			$this->markTestSkipped( 'phpcs binary not installed; run composer install.' );
		}

		// `--report=json` emits structured violations with source codes, which is stable
		// across PHPCS terminal-width wrapping.
		$cmd = sprintf(
			'%s --standard=%s --report=json --no-colors %s 2>/dev/null',
			escapeshellarg( $phpcs ),
			escapeshellarg( $standard ),
			escapeshellarg( $fixture )
		);

		exec( $cmd, $output, $exit_code );
		$json = implode( "\n", $output );
		$data = json_decode( $json, true );

		$this->assertNotSame( 0, $exit_code, "Expected fixture {$fixture} to fail phpcs but it passed. Output:\n{$json}" );
		$this->assertIsArray( $data, "phpcs did not emit JSON; got:\n{$json}" );

		$found_sources = array();
		foreach ( ( $data['files'] ?? array() ) as $file_report ) {
			foreach ( ( $file_report['messages'] ?? array() ) as $message ) {
				$found_sources[] = $message['source'] ?? '';
			}
		}

		$this->assertContains(
			$expected_code,
			$found_sources,
			"Expected source code {$expected_code} not found among: " . implode( ', ', $found_sources )
		);
	}

	public function test_clean_fixture_passes(): void {
		$phpcs    = dirname( __DIR__, 2 ) . '/vendor/bin/phpcs';
		$standard = dirname( __DIR__, 2 ) . '/tools/phpcs-sniffs/SemanticPosts';

		if ( ! file_exists( $phpcs ) ) {
			$this->markTestSkipped( 'phpcs binary not installed; run composer install.' );
		}

		$fixture = __DIR__ . '/fixtures/clean.php';
		$this->assertFileExists( $fixture );

		$cmd = sprintf(
			'%s --standard=%s --no-colors %s 2>&1',
			escapeshellarg( $phpcs ),
			escapeshellarg( $standard ),
			escapeshellarg( $fixture )
		);

		exec( $cmd, $output, $exit_code );
		$this->assertSame( 0, $exit_code, "Clean fixture unexpectedly failed phpcs:\n" . implode( "\n", $output ) );
	}
}
