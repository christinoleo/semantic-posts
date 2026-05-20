<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsRepository;

final class CostEstimatorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_zero_posts_costs_zero(): void {
		$est = new CostEstimator();
		$out = $est->estimate( 0, SettingsRepository::MODEL_SMALL );
		$this->assertSame( 0.0, $out['estimated_usd'] );
		$this->assertSame( 0, $out['total_tokens'] );
	}

	public function test_small_model_pricing_matches_published_rate(): void {
		// text-embedding-3-small: $0.020 per 1M tokens.
		// 1000 posts × 500 avg tokens = 500_000 tokens → $0.010
		$est = new CostEstimator();
		$out = $est->estimate( 1000, SettingsRepository::MODEL_SMALL );
		$this->assertSame( 500_000, $out['total_tokens'] );
		$this->assertEqualsWithDelta( 0.01, $out['estimated_usd'], 0.0001 );
	}

	public function test_large_model_pricing_uses_higher_rate(): void {
		// text-embedding-3-large: $0.130 per 1M tokens.
		// 1000 posts × 500 avg tokens = 500_000 tokens → $0.065
		$est = new CostEstimator();
		$out = $est->estimate( 1000, SettingsRepository::MODEL_LARGE );
		$this->assertEqualsWithDelta( 0.065, $out['estimated_usd'], 0.0001 );
	}

	public function test_unknown_model_falls_back_to_small_pricing(): void {
		$est       = new CostEstimator();
		$expected  = $est->estimate( 100, SettingsRepository::MODEL_SMALL );
		$actual    = $est->estimate( 100, 'pirate/davinci-yarr' );
		$this->assertSame( $expected['estimated_usd'], $actual['estimated_usd'] );
	}

	public function test_custom_avg_tokens_via_filter(): void {
		\Brain\Monkey\Filters\expectApplied( 'semantic_posts_cost_avg_tokens_per_post' )
			->andReturn( 1000 );
		$est = new CostEstimator();
		$out = $est->estimate( 100, SettingsRepository::MODEL_SMALL );
		$this->assertSame( 100_000, $out['total_tokens'] );
	}

	public function test_negative_post_count_is_treated_as_zero(): void {
		$est = new CostEstimator();
		$out = $est->estimate( -5, SettingsRepository::MODEL_SMALL );
		$this->assertSame( 0.0, $out['estimated_usd'] );
	}

	public function test_estimate_includes_model_label_for_ui(): void {
		$est = new CostEstimator();
		$out = $est->estimate( 100, SettingsRepository::MODEL_LARGE );
		$this->assertArrayHasKey( 'model', $out );
		$this->assertSame( SettingsRepository::MODEL_LARGE, $out['model'] );
	}
}
