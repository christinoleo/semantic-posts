<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\Wiper;

require_once __DIR__ . '/ColdStartProcessorTest.php';

/**
 * Capturing wpdb stub. Records queries + returns scripted rowcounts.
 */
final class CapturingWpdb {
	public string $postmeta             = 'wp_postmeta';
	/** @var string[] */
	public array $captured              = array();
	public int $delete_returns          = 0;
	public function esc_like( string $s ): string {
		return $s;
	}
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%s/', "'" . (string) $a . "'", $sql, 1 );
		}
		return $sql;
	}
	public function query( string $sql ): int {
		$this->captured[] = $sql;
		return $this->delete_returns;
	}
}

final class WiperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wipe_deletes_sp_postmeta_and_resets_cursor(): void {
		global $wpdb;
		$wpdb                  = new CapturingWpdb();
		$wpdb->delete_returns  = 17;

		$state = new InMemoryState();
		$state->write(
			array(
				'cold_start' => array(
					'phase'             => ColdStartProcessor::PHASE_GRAPH_KNN,
					'last_processed_id' => 99,
					'completed'         => 12345,
				),
			)
		);

		$wiper = new Wiper( $state );
		$rows  = $wiper->wipe_embeddings();

		$this->assertSame( 17, $rows );
		$this->assertSame( ColdStartProcessor::PHASE_IDLE, $state->state['cold_start']['phase'] );
		$this->assertSame( 0, $state->state['cold_start']['last_processed_id'] );
		$this->assertCount( 1, $wpdb->captured );
		$this->assertStringContainsString( "LIKE '_sp_%'", $wpdb->captured[0] );
	}

	public function test_wipe_is_safe_when_no_wpdb_available(): void {
		global $wpdb;
		$wpdb  = null;
		$state = new InMemoryState();
		$wiper = new Wiper( $state );
		$rows  = $wiper->wipe_embeddings();
		$this->assertSame( 0, $rows );
	}
}
