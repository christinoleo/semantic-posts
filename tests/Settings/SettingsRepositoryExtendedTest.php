<?php
/**
 * Coverage for TB-12 extended settings fields: embedding_model, related_count,
 * quality_bounded, min_items, score_threshold, cron_frequency, api_key_set.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Settings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Settings\SettingsRepository;

final class SettingsRepositoryExtendedTest extends TestCase {

	/** @var array<string,mixed> */
	private array $option_store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->option_store = array();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->option_store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->option_store[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'sanitize_key' )->alias(
			static fn( $k ) => strtolower( (string) preg_replace( '/[^a-z0-9_\-]/i', '', (string) $k ) )
		);
		Functions\when( 'sanitize_text_field' )->alias(
			static fn( $v ) => trim( (string) preg_replace( '/\s+/u', ' ', (string) $v ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_embedding_model_is_text_embedding_3_small(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'openai/text-embedding-3-small', $repo->embedding_model() );
	}

	public function test_unknown_embedding_model_falls_back_to_default(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'embedding_model' => 'pirate/davinci-yarr' ) );
		$this->assertSame( 'openai/text-embedding-3-small', $repo->embedding_model() );
	}

	public function test_text_embedding_3_large_is_a_valid_model(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'embedding_model' => 'openai/text-embedding-3-large' ) );
		$this->assertSame( 'openai/text-embedding-3-large', $repo->embedding_model() );
	}

	public function test_default_related_count_is_five(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 5, $repo->related_count() );
	}

	public function test_related_count_clamps_below_three(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'related_count' => 1 ) );
		$this->assertSame( 3, $repo->related_count() );
	}

	public function test_related_count_clamps_above_ten(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'related_count' => 50 ) );
		$this->assertSame( 10, $repo->related_count() );
	}

	public function test_quality_bounded_defaults_off(): void {
		$repo = new SettingsRepository();
		$this->assertFalse( $repo->quality_bounded() );
	}

	public function test_quality_bounded_persists_when_on(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'quality_bounded' => '1',
				'min_items'       => 2,
				'score_threshold' => 0.45,
			)
		);
		$this->assertTrue( $repo->quality_bounded() );
		$this->assertSame( 2, $repo->min_items() );
		$this->assertSame( 0.45, $repo->score_threshold() );
	}

	public function test_score_threshold_clamps_outside_zero_one_range(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'score_threshold' => 1.5 ) );
		$this->assertSame( 1.0, $repo->score_threshold() );
		$repo->save( array( 'score_threshold' => -2.0 ) );
		$this->assertSame( 0.0, $repo->score_threshold() );
	}

	public function test_min_items_minimum_is_one(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'min_items' => 0 ) );
		$this->assertSame( 1, $repo->min_items() );
	}

	public function test_default_cron_frequency_is_hourly(): void {
		$repo = new SettingsRepository();
		$this->assertSame( 'hourly', $repo->cron_frequency() );
	}

	public function test_cron_frequency_accepts_known_intervals(): void {
		$repo = new SettingsRepository();
		foreach ( array( 'hourly', 'six_hours', 'daily' ) as $value ) {
			$repo->save( array( 'cron_frequency' => $value ) );
			$this->assertSame( $value, $repo->cron_frequency() );
		}
	}

	public function test_cron_frequency_falls_back_when_unknown(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'cron_frequency' => 'every_second' ) );
		$this->assertSame( 'hourly', $repo->cron_frequency() );
	}

	public function test_saved_payload_under_one_kb(): void {
		$repo = new SettingsRepository();
		$repo->save(
			array(
				'post_types'      => array( 'post', 'page', 'product', 'event', 'recipe' ),
				'display_mode'    => SettingsRepository::MODE_AUTO_INJECT,
				'ranking_mode'    => \SemanticPosts\Ranking\Mode::DIVERSE_MIX,
				'embedding_model' => 'openai/text-embedding-3-large',
				'related_count'   => 10,
				'quality_bounded' => '1',
				'min_items'       => 3,
				'score_threshold' => 0.72,
				'cron_frequency'  => 'six_hours',
			)
		);
		$stored = $this->option_store[ SettingsRepository::OPTION_NAME ];
		$this->assertLessThan( 1024, strlen( serialize( $stored ) ), 'NFR-HOST-5: option payload must stay <1KB.' );
	}
}
