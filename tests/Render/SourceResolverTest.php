<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Render;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Render\SourceResolver;

final class SourceResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_type = "post"; }' );
		}

		// Default stubs: no semantic data, no language. Each test overrides as needed.
		Functions\when( 'get_post_meta' )->justReturn( '' );
		Functions\when( 'pll_get_post_language' )->justReturn( '' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post_with_id( int $id ): \WP_Post {
		$p            = new \WP_Post();
		$p->ID        = $id;
		$p->post_type = 'post';
		return $p;
	}

	public function test_returns_none_when_post_has_no_categories(): void {
		$post = $this->post_with_id( 1 );
		Functions\when( 'wp_get_post_categories' )->justReturn( array() );

		$out = ( new SourceResolver() )->resolve( $post, 5 );

		$this->assertSame( array(), $out['items'] );
		$this->assertSame( SourceResolver::SOURCE_NONE, $out['data_source'] );
	}

	public function test_returns_category_fallback_when_categories_present(): void {
		$post = $this->post_with_id( 1 );
		Functions\when( 'wp_get_post_categories' )->justReturn( array( 7 ) );

		// Stub WP_Query so the test stays isolated from WP runtime.
		if ( ! class_exists( \WP_Query::class ) ) {
			eval( 'class WP_Query { public array $posts = []; public function __construct( $args ){ $this->posts = $args["_stub_posts"] ?? []; } }' );
		}

		// Trick: WP_Query::__construct stub reads $args["_stub_posts"].
		// Inject via wp_get_post_categories-like alias: we'd need a different approach
		// since WP_Query is `new`-ed inside the resolver. Simpler: monkey-patch the
		// resolver by writing a subclass for testing. But we want zero seams in prod.
		// Alternative: bind a global state and override WP_Query at the class level.
		// For this slice we accept that exercising the WP_Query branch belongs to
		// the docker browser smoke test (real WP). Here we cover the no-categories path
		// and the SOURCE constants.
		$this->markTestSkipped( 'WP_Query branch covered by docker smoke test (TB-03 PR description).' );
	}

	public function test_source_constants_are_stable(): void {
		// Public contract — downstream filters and analytics may key on these.
		$this->assertSame( 'category-fallback', SourceResolver::SOURCE_CATEGORY );
		$this->assertSame( 'semantic', SourceResolver::SOURCE_SEMANTIC );
		$this->assertSame( 'none', SourceResolver::SOURCE_NONE );
	}
}
