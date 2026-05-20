<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Verification\DriftNotice;
use SemanticPosts\Verification\VerificationPass;

require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';

use SemanticPosts\Tests\Indexing\InMemoryState;

final class DriftNoticeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function build( InMemoryState $state ): DriftNotice {
		$verification = new VerificationPass(
			$state,
			new NeighborStore(),
			static fn(): int => 1000,
			static fn() => array(),
			static fn() => array(),
			static fn() => null
		);
		return new DriftNotice( $state, $verification );
	}

	public function test_renders_nothing_when_mrd_below_threshold(): void {
		$state                                  = new InMemoryState();
		$state->state['verification']['last_mrd'] = 0.5;
		$notice = $this->build( $state );

		ob_start();
		$notice->maybe_render();
		$out = (string) ob_get_clean();

		$this->assertSame( '', trim( $out ) );
	}

	public function test_renders_warning_when_mrd_at_or_above_threshold(): void {
		$state                                  = new InMemoryState();
		$state->state['verification']['last_mrd'] = 2.0;
		$notice = $this->build( $state );

		ob_start();
		$notice->maybe_render();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'mrd-drift', $out );
		$this->assertStringContainsString( 'notice-warning', $out );
	}

	public function test_threshold_filter_overrides_default(): void {
		$state                                  = new InMemoryState();
		$state->state['verification']['last_mrd'] = 0.8;
		Filters\expectApplied( 'semantic_posts_verification_threshold' )->andReturn( 0.5 );

		$notice = $this->build( $state );

		ob_start();
		$notice->maybe_render();
		$out = (string) ob_get_clean();
		$this->assertStringContainsString( 'mrd-drift', $out );
	}
}
