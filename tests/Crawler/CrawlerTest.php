<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Crawler;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\Vector;

final class CrawlerTest extends TestCase {

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
		// Brain\Monkey can't undefine PHP functions between tests — once
		// `pll_get_post_language` is aliased in one test, function_exists()
		// returns true everywhere. Default-stub it to "no language" so the
		// language filter is a pass-through in tests that don't care.
		Functions\when( 'pll_get_post_language' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seed a deterministic embedding for $post_id. Each post gets a
	 * 3-dim unit-ish vector based on its ID so cosines are predictable.
	 *
	 * Posts 1..5 share a "tech" axis; 6..10 share a different "cooking" axis.
	 */
	private function seed_embedding( int $post_id ): void {
		// Two clusters.
		if ( $post_id <= 5 ) {
			$vec = array( 0.9, 0.1, 0.0 );
		} else {
			$vec = array( 0.0, 0.1, 0.9 );
		}
		// Slight perturbation so ordering is stable but not all-equal.
		$vec[1] += ( $post_id * 0.001 );
		$this->meta[ $post_id ][ Vector::POSTMETA_KEY ] = Vector::encode( $vec );
	}

	private function build_crawler( array $random_sample = array() ): Crawler {
		return new Crawler(
			new NeighborStore(),
			static function () use ( $random_sample ): array {
				return $random_sample;
			}
		);
	}

	public function test_update_returns_zero_when_source_has_no_embedding(): void {
		$crawler = $this->build_crawler();
		$this->assertSame( 0, $crawler->update( 1 ) );
	}

	public function test_update_picks_same_cluster_posts_as_top_k(): void {
		// Seed posts 1..10 in two clusters of 5 (tech=1..5, cooking=6..10).
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->seed_embedding( $i );
		}

		// Source is post 1 (tech cluster). 4 tech siblings (2..5) + 5 cooking candidates.
		$crawler = $this->build_crawler( array( 2, 3, 4, 5, 6, 7, 8, 9, 10 ) );
		$ops     = $crawler->update( 1 );

		$related = $this->meta[1]['_sp_related'];

		// Featured (top score) MUST be tech cluster.
		$tech_ids = array( 2, 3, 4, 5 );
		$top_id   = array_key_first( $related );
		$this->assertContains( $top_id, $tech_ids );

		// The 4 highest-scoring entries must all be tech (cooking cluster
		// only fills the 5th slot when no more tech candidates exist).
		$top_4 = array_slice( $related, 0, 4, true );
		foreach ( array_keys( $top_4 ) as $id ) {
			$this->assertContains( $id, $tech_ids, "Top-4 should be tech cluster only; got {$id}." );
		}

		$this->assertLessThanOrEqual( Crawler::SOFT_BUDGET, $ops, "Cosine ops exceeded soft budget: {$ops}" );
	}

	public function test_update_writes_inbound_mirrors_for_new_neighbors(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_embedding( $i );
		}
		$crawler = $this->build_crawler( array( 2, 3, 4, 5 ) );
		$crawler->update( 1 );

		// Posts 2..5 should now list post 1 in their _sp_inbound.
		foreach ( array( 2, 3, 4, 5 ) as $id ) {
			$inbound = $this->meta[ $id ]['_sp_inbound'] ?? array();
			$this->assertContains( 1, $inbound, "Post {$id} should have post 1 in _sp_inbound after crawl." );
		}
	}

	public function test_update_removes_inbound_when_neighbor_falls_out_of_top_k(): void {
		for ( $i = 1; $i <= 10; $i++ ) {
			$this->seed_embedding( $i );
		}

		// Pre-seed post 1 with a stale _sp_related containing a cooking-cluster post.
		$this->meta[1]['_sp_related'] = array( 6 => 0.1 );
		$this->meta[6]['_sp_inbound'] = array( 1 );

		$crawler = $this->build_crawler( array( 2, 3, 4, 5, 7, 8, 9, 10 ) );
		$crawler->update( 1 );

		// Post 6 is no longer in post 1's new top-K → its _sp_inbound should drop post 1.
		$inbound_6 = $this->meta[6]['_sp_inbound'] ?? array();
		$this->assertNotContains( 1, $inbound_6, 'Stale inbound mirror was not cleaned.' );
	}

	public function test_update_respects_language_filter_via_polylang(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_embedding( $i );
		}

		Functions\when( 'pll_get_post_language' )->alias(
			static fn( $id ) => ( $id <= 2 ) ? 'en' : 'pt'
		);
		// Brain\Monkey returns the default for apply_filters unless overridden.
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$crawler = $this->build_crawler( array( 2, 3, 4, 5 ) );
		$crawler->update( 1 );

		$related = $this->meta[1]['_sp_related'] ?? array();
		// Only post 2 (en) should be in the top-K; 3,4,5 (pt) are filtered out.
		$this->assertSame( array( 2 ), array_keys( $related ) );
	}

	public function test_update_language_filter_can_be_disabled_via_filter(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->seed_embedding( $i );
		}

		Functions\when( 'pll_get_post_language' )->alias( static fn( $id ) => ( $id <= 2 ) ? 'en' : 'pt' );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( true );

		$crawler = $this->build_crawler( array( 2, 3, 4, 5 ) );
		$crawler->update( 1 );

		$related = $this->meta[1]['_sp_related'] ?? array();
		// With filter disabled, all candidates from the same cluster are eligible.
		$this->assertGreaterThan( 1, count( $related ), 'Expected multiple neighbors when language filter is disabled.' );
	}

	public function test_update_returns_op_count_for_100_post_cluster(): void {
		// 100 posts, all in tech cluster (worst case for candidate-set size).
		for ( $i = 1; $i <= 100; $i++ ) {
			$this->seed_embedding( $i <= 50 ? $i : ( $i % 5 + 1 ) ); // shape doesn't matter for op count
			$this->meta[ $i ][ Vector::POSTMETA_KEY ] = Vector::encode( array( 0.9, 0.1 * ( $i / 100 ), 0.0 ) );
		}

		// Pre-seed source with 5 outbound + 5 inbound for representative candidate set.
		$this->meta[1]['_sp_related'] = array( 2 => 0.9, 3 => 0.85, 4 => 0.8, 5 => 0.75, 6 => 0.7 );
		$this->meta[1]['_sp_inbound'] = array( 7, 8, 9, 10, 11 );

		$crawler = $this->build_crawler( array( 12, 13, 14, 15, 16, 17, 18, 19, 20, 21 ) );
		$ops     = $crawler->update( 1 );

		$this->assertLessThanOrEqual( Crawler::SOFT_BUDGET, $ops, "Crawler did {$ops} ops on 100-post fixture; expected ≤ " . Crawler::SOFT_BUDGET );
	}
}
