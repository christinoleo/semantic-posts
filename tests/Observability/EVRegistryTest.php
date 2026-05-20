<?php
/**
 * Coverage for TB-16 — EV registry resolution + architecture.md sync.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Observability;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Observability\EVRegistry;
use SemanticPosts\Settings\SettingsRepository;

final class EVRegistryTest extends TestCase {

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
		Functions\when( 'sanitize_key' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_registry_contains_all_ev_ids(): void {
		$registry = new EVRegistry( new SettingsRepository() );
		$ids      = $registry->ids();
		$expected = array();
		for ( $i = 1; $i <= 15; $i++ ) {
			$expected[] = sprintf( 'EV-%02d', $i );
		}
		$this->assertSame( $expected, $ids );
	}

	public function test_default_source_when_value_unchanged(): void {
		$registry = new EVRegistry( new SettingsRepository() );
		$rows     = $registry->entries();
		// EV-01 has no filter or setting.
		$ev01     = $rows[0];
		$this->assertSame( 'EV-01', $ev01['id'] );
		$this->assertSame( EVRegistry::SOURCE_DEFAULT, $ev01['source'] );
		$this->assertSame( $ev01['default'], $ev01['current'] );
	}

	public function test_filtered_source_when_third_party_overrides(): void {
		Filters\expectApplied( 'semantic_posts_verification_threshold' )->andReturn( 3.0 );
		Filters\expectApplied( 'semantic_posts_recency_decay' )->andReturn( 180.0 );
		Filters\expectApplied( 'semantic_posts_mmr_lambda' )->andReturn( 0.7 );
		Filters\expectApplied( 'semantic_posts_cost_avg_tokens_per_post' )->andReturn( 500 );

		$registry = new EVRegistry( new SettingsRepository() );
		$rows     = array_column( $registry->entries(), null, 'id' );

		$this->assertSame( EVRegistry::SOURCE_FILTERED, $rows['EV-05']['source'] );
		$this->assertSame( 3.0, $rows['EV-05']['current'] );
		// EV-11 was not overridden in this run → still 'default'.
		$this->assertSame( EVRegistry::SOURCE_DEFAULT, $rows['EV-11']['source'] );
	}

	public function test_setting_tied_ev_reads_from_settings_repository(): void {
		$repo = new SettingsRepository();
		$repo->save( array( 'score_threshold' => 0.42, 'quality_bounded' => '1' ) );

		$registry = new EVRegistry( $repo );
		$rows     = array_column( $registry->entries(), null, 'id' );

		$this->assertSame( EVRegistry::SOURCE_SETTING, $rows['EV-13']['source'] );
		$this->assertSame( 0.42, $rows['EV-13']['current'] );
	}

	public function test_architecture_md_mentions_every_entry(): void {
		$path = dirname( __DIR__, 2 ) . '/_bmad-output/planning-artifacts/architecture.md';
		if ( ! is_readable( $path ) ) {
			$this->markTestSkipped( 'architecture.md not available in this checkout.' );
		}
		$contents = (string) file_get_contents( $path );

		$registry = new EVRegistry( new SettingsRepository() );
		foreach ( $registry->ids() as $id ) {
			$this->assertStringContainsString(
				$id,
				$contents,
				sprintf( 'architecture.md is missing %s — the registry and the doc are out of sync.', $id )
			);
		}
	}
}
