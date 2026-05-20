<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Crawler;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\Vector;

final class CrawlerInsertTest extends TestCase {

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
		Functions\when( 'pll_get_post_language' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function seed( int $id, array $vec ): void {
		$this->meta[ $id ][ Vector::POSTMETA_KEY ] = Vector::encode( $vec );
	}

	public function test_insert_phase_1_brute_force_on_small_corpus(): void {
		// 5 indexed posts (1..5) with distinct vectors; source post 100 should
		// match post 1 most closely.
		$this->seed( 1, array( 1.0, 0.0, 0.0 ) );
		$this->seed( 2, array( 0.95, 0.05, 0.0 ) );
		$this->seed( 3, array( 0.5, 0.5, 0.0 ) );
		$this->seed( 4, array( 0.0, 0.0, 1.0 ) );
		$this->seed( 5, array( 0.0, 1.0, 0.0 ) );
		$this->seed( 100, array( 0.99, 0.01, 0.0 ) );

		$crawler = new Crawler(
			new NeighborStore(),
			static fn() => array(),
			static fn() => array( 1, 2, 3, 4, 5 )
		);

		$ops = $crawler->insert( 100 );

		// Brute-force checked all 5 indexed posts.
		$this->assertSame( 5, $ops );

		$related = $this->meta[100]['_sp_related'];
		$top_id  = array_key_first( $related );
		$this->assertSame( 1, $top_id, 'Featured should be post 1 (highest cosine).' );
	}

	public function test_insert_phase_1_writes_inbound_mirrors(): void {
		$this->seed( 1, array( 1.0, 0.0, 0.0 ) );
		$this->seed( 2, array( 0.9, 0.1, 0.0 ) );
		$this->seed( 100, array( 0.95, 0.05, 0.0 ) );

		$crawler = new Crawler(
			new NeighborStore(),
			static fn() => array(),
			static fn() => array( 1, 2 )
		);
		$crawler->insert( 100 );

		// Posts 1 and 2 should now have 100 in their _sp_inbound.
		$this->assertContains( 100, $this->meta[1]['_sp_inbound'] ?? array() );
		$this->assertContains( 100, $this->meta[2]['_sp_inbound'] ?? array() );
	}

	public function test_insert_phase_2_kicks_in_above_threshold(): void {
		// Seed 6 indexed posts; set N_b=3 so insert(100) takes the Phase 2 path.
		for ( $i = 1; $i <= 6; $i++ ) {
			$this->seed( $i, array( 0.9 + ( $i * 0.01 ), 0.1, 0.0 ) );
		}
		$this->seed( 100, array( 0.95, 0.05, 0.0 ) );

		// Wire up a sparse graph so the walk can expand: post 1 → 2, 2 → 3, etc.
		$this->meta[1]['_sp_related'] = array( 2 => 0.9 );
		$this->meta[2]['_sp_related'] = array( 3 => 0.9 );
		$this->meta[3]['_sp_related'] = array( 4 => 0.9 );
		$this->meta[4]['_sp_related'] = array( 5 => 0.9 );
		$this->meta[5]['_sp_related'] = array( 6 => 0.9 );

		$crawler = new Crawler(
			new NeighborStore(),
			static fn() => array(),
			static fn() => array( 1, 2, 3, 4, 5, 6 ),
			3 // phase_1_limit override
		);

		$ops = $crawler->insert( 100 );

		// Phase 2: walk visits ≤ B_v=300 nodes. With 6 posts in the graph,
		// far fewer than 300 are reachable.
		$this->assertLessThanOrEqual( Crawler::VISIT_BUDGET, $ops );
		$this->assertGreaterThan( 0, $ops, 'Phase 2 walk should still touch at least one node.' );

		// Top-K populated.
		$this->assertNotEmpty( $this->meta[100]['_sp_related'] ?? array() );
	}

	public function test_insert_phase_2_respects_visit_budget(): void {
		// Build a 1000-node line graph (1→2→3→...→1000) and run Phase 2.
		// The walk must stop at VISIT_BUDGET regardless of path length.
		for ( $i = 1; $i <= 1000; $i++ ) {
			$this->seed( $i, array( 0.001 * $i, 1.0 - 0.001 * $i, 0.0 ) );
			if ( $i < 1000 ) {
				$this->meta[ $i ]['_sp_related'] = array( $i + 1 => 0.9 );
			}
		}
		$this->seed( 5000, array( 0.5, 0.5, 0.0 ) );

		$crawler = new Crawler(
			new NeighborStore(),
			static fn() => array(),
			static fn() => range( 1, 1000 ),
			10 // force Phase 2
		);
		$ops = $crawler->insert( 5000 );

		$this->assertLessThanOrEqual( Crawler::VISIT_BUDGET, $ops, "Walk exceeded VISIT_BUDGET: {$ops}" );
	}

	public function test_insert_with_no_indexed_posts_writes_empty_top_k(): void {
		$this->seed( 100, array( 0.5, 0.5, 0.0 ) );

		$crawler = new Crawler( new NeighborStore(), static fn() => array(), static fn() => array() );
		$ops     = $crawler->insert( 100 );

		$this->assertSame( 0, $ops );
		$this->assertArrayNotHasKey( '_sp_related', $this->meta[100] );
	}
}
