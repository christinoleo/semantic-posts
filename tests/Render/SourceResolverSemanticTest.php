<?php
/**
 * Coverage for TB-11 — semantic source + language filter + quality threshold
 * + category-padding behaviour in SourceResolver.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Render;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Render\SourceResolver;

final class SourceResolverSemanticTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	/** @var array<int,string> */
	private array $languages = array();

	/** @var int[] Category-fallback candidates returned in order. */
	private array $category_candidates = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_type = "post"; }' );
		}
		if ( ! class_exists( \WP_Query::class ) ) {
			eval( '
				class WP_Query {
					public array $posts = [];
					public function __construct( $args ) {
						$cb = \SemanticPosts\Tests\Render\SourceResolverSemanticTest::$wp_query_callback ?? null;
						$this->posts = is_callable( $cb ) ? (array) $cb( $args ) : [];
					}
				}
			' );
		}

		self::$wp_query_callback = function ( $args ) {
			return $this->category_candidates;
		};

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key /* , $single */ ) {
				return $this->meta[ (int) $post_id ][ (string) $key ] ?? '';
			}
		);
		Functions\when( 'wp_get_post_categories' )->justReturn( array( 1 ) );
		Functions\when( 'pll_get_post_language' )->alias(
			function ( $post_id ) {
				return $this->languages[ (int) $post_id ] ?? '';
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @var (\Closure|null) */
	public static $wp_query_callback = null;

	private function post( int $id ): \WP_Post {
		$p            = new \WP_Post();
		$p->ID        = $id;
		$p->post_type = 'post';
		return $p;
	}

	public function test_semantic_path_returns_sp_related_ids_when_present(): void {
		$this->meta[1]['_sp_related'] = array(
			10 => 0.9,
			11 => 0.8,
			12 => 0.7,
		);
		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.0 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( SourceResolver::SOURCE_SEMANTIC, $result['data_source'] );
		$this->assertSame( array( 10, 11, 12 ), $result['items'] );
		$this->assertSame( SourceResolver::SOURCE_SEMANTIC, $result['item_sources'][10] );
	}

	public function test_language_filter_drops_foreign_candidates(): void {
		$this->meta[1]['_sp_related'] = array(
			10 => 0.9,
			11 => 0.8,
			12 => 0.7,
		);
		$this->languages = array(
			1  => 'en',
			10 => 'en',
			11 => 'pt', // dropped
			12 => 'en',
		);
		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.0 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( array( 10, 12 ), $result['items'] );
	}

	public function test_disable_language_filter_passes_all_candidates_through(): void {
		$this->meta[1]['_sp_related'] = array( 10 => 0.9, 11 => 0.8 );
		$this->languages              = array( 1 => 'en', 10 => 'en', 11 => 'pt' );
		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.0 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( true );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( array( 10, 11 ), $result['items'] );
	}

	public function test_quality_threshold_drops_low_score_items(): void {
		$this->meta[1]['_sp_related'] = array(
			10 => 0.9,
			11 => 0.5,
			12 => 0.2, // below 0.3
		);
		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.3 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( array( 10, 11 ), $result['items'] );
	}

	public function test_quality_threshold_active_means_no_category_padding(): void {
		// Only 1 item passes threshold; with threshold ON, do NOT pad.
		$this->meta[1]['_sp_related'] = array(
			10 => 0.9,
			11 => 0.1, // below 0.3
		);
		$this->category_candidates    = array( 100, 101, 102 );

		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.3 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( array( 10 ), $result['items'], 'Quality-bounded mode must NOT pad with category-fallback.' );
		$this->assertSame( SourceResolver::SOURCE_SEMANTIC, $result['data_source'] );
	}

	public function test_threshold_off_pads_with_category_when_semantic_short(): void {
		// Only 2 items remain after language filter; quality-bounded OFF → pad.
		$this->meta[1]['_sp_related'] = array(
			10 => 0.9,
			11 => 0.8,
		);
		$this->category_candidates    = array( 100, 101, 102 );

		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.0 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertCount( 5, $result['items'] );
		$this->assertSame( array( 10, 11, 100, 101, 102 ), $result['items'] );
		$this->assertSame( SourceResolver::SOURCE_SEMANTIC, $result['data_source'] );
		// Per-item attribution: first two semantic, rest category-fallback.
		$this->assertSame( SourceResolver::SOURCE_SEMANTIC, $result['item_sources'][10] );
		$this->assertSame( SourceResolver::SOURCE_CATEGORY, $result['item_sources'][100] );
	}

	public function test_padding_dedupes_against_semantic_items(): void {
		$this->meta[1]['_sp_related'] = array( 10 => 0.9, 11 => 0.8 );
		// Category fallback returns 11 (already in semantic) and others.
		$this->category_candidates    = array( 11, 12, 13, 14 );

		Filters\expectApplied( 'semantic_posts_min_score' )->andReturn( 0.0 );
		Filters\expectApplied( 'semantic_posts_disable_language_filter' )->andReturn( false );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		// 11 should appear once (semantic) and 12,13,14 fill the rest.
		$this->assertSame( array( 10, 11, 12, 13, 14 ), $result['items'] );
	}

	public function test_no_semantic_no_category_returns_none(): void {
		$this->category_candidates = array();
		Functions\when( 'wp_get_post_categories' )->justReturn( array() );

		$result = ( new SourceResolver() )->resolve( $this->post( 1 ), 5 );

		$this->assertSame( array(), $result['items'] );
		$this->assertSame( SourceResolver::SOURCE_NONE, $result['data_source'] );
	}
}
