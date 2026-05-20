<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Indexing\CleanupRouter;
use SemanticPosts\Indexing\HashDiffDetector;

final class CleanupRouterTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta = array();

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key /* , $single */ ) {
				return $this->meta[ (int) $post_id ][ (string) $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ (int) $post_id ][ (string) $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cleanup_removes_all_sp_keys_for_target_post(): void {
		$this->meta[5] = array(
			'_sp_embedding' => 'whatever',
			'_sp_related'   => array( 7 => 0.9 ),
			'_sp_inbound'   => array(),
			'_sp_text_hash' => 'abc',
			'_sp_dirty'     => 1,
		);

		$router = new CleanupRouter( new NeighborStore(), new HashDiffDetector() );
		$router->cleanup( 5 );

		$this->assertSame( array(), $this->meta[5] ?? array() );
	}

	public function test_cleanup_invalidates_inbound_dependents(): void {
		// Post 5 is referenced by posts 11 and 12 in their _sp_related.
		$this->meta[5]['_sp_inbound']   = array( 11, 12 );
		$this->meta[11]['_sp_related']  = array( 5 => 0.9, 17 => 0.5 );
		$this->meta[12]['_sp_related']  = array( 5 => 0.7 );

		$router = new CleanupRouter( new NeighborStore(), new HashDiffDetector() );
		$router->cleanup( 5 );

		// Post 11's _sp_related drops 5 but keeps 17.
		$this->assertSame( array( 17 => 0.5 ), $this->meta[11]['_sp_related'] );
		// Post 12's _sp_related had only 5 → meta row deleted.
		$this->assertArrayNotHasKey( '_sp_related', $this->meta[12] ?? array() );
	}

	public function test_cleanup_self_reference_in_inbound_is_skipped(): void {
		// Defensive: if the inbound list somehow includes the post itself, don't
		// try to remove ourselves from a row we just deleted.
		$this->meta[5]['_sp_inbound']  = array( 5, 11 );
		$this->meta[11]['_sp_related'] = array( 5 => 0.9 );

		$router = new CleanupRouter( new NeighborStore(), new HashDiffDetector() );
		$router->cleanup( 5 );

		$this->assertArrayNotHasKey( '_sp_related', $this->meta[11] ?? array() );
	}
}
