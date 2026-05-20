<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Crawler;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;

final class NeighborStoreTest extends TestCase {

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

	public function test_read_related_returns_empty_when_no_meta(): void {
		$this->assertSame( array(), ( new NeighborStore() )->read_related( 1 ) );
	}

	public function test_read_related_coerces_keys_and_values(): void {
		$this->meta[1]['_sp_related'] = array( '5' => '0.9', '7' => 0.75 );
		$out                          = ( new NeighborStore() )->read_related( 1 );
		$this->assertSame( 0.9, $out[5] );
		$this->assertSame( 0.75, $out[7] );
	}

	public function test_read_inbound_returns_int_array(): void {
		$this->meta[1]['_sp_inbound'] = array( '5', '7', '5', '11' );
		$this->assertSame( array( 5, 7, 11 ), ( new NeighborStore() )->read_inbound( 1 ) );
	}

	public function test_delete_for_post_removes_both_keys(): void {
		$this->meta[1]['_sp_related'] = array( 5 => 0.9 );
		$this->meta[1]['_sp_inbound'] = array( 7 );
		( new NeighborStore() )->delete_for_post( 1 );

		$this->assertArrayNotHasKey( '_sp_related', $this->meta[1] );
		$this->assertArrayNotHasKey( '_sp_inbound', $this->meta[1] );
	}

	public function test_remove_neighbor_drops_one_entry_and_keeps_others(): void {
		$this->meta[1]['_sp_related'] = array( 5 => 0.9, 7 => 0.5, 9 => 0.3 );
		( new NeighborStore() )->remove_neighbor( 1, 7 );
		$this->assertSame( array( 5 => 0.9, 9 => 0.3 ), $this->meta[1]['_sp_related'] );
	}

	public function test_remove_neighbor_deletes_meta_when_list_empties(): void {
		$this->meta[1]['_sp_related'] = array( 7 => 0.5 );
		( new NeighborStore() )->remove_neighbor( 1, 7 );
		$this->assertArrayNotHasKey( '_sp_related', $this->meta[1] );
	}

	public function test_remove_neighbor_noop_when_id_absent(): void {
		$this->meta[1]['_sp_related'] = array( 5 => 0.9 );
		( new NeighborStore() )->remove_neighbor( 1, 999 );
		$this->assertSame( array( 5 => 0.9 ), $this->meta[1]['_sp_related'] );
	}
}
