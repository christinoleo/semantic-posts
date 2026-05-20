<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Render;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Render\ContentFilter;
use SemanticPosts\Render\Renderer;
use SemanticPosts\Render\Shortcode;
use SemanticPosts\Render\SourceResolver;
use SemanticPosts\Settings\SettingsRepository;

/** Hand-rolled test double — PHPUnit can't mock `final` Renderer. */
final class StubRenderer extends Renderer {
	public int $calls    = 0;
	public string $reply = '<section>x</section>';

	public function __construct() {
		parent::__construct( new SourceResolver() );
	}

	public function render( \WP_Post $post, int $count = 5 ): string {
		++$this->calls;
		return $this->reply;
	}
}

/** Hand-rolled test double — PHPUnit can't mock `final` SettingsRepository. */
final class StubSettings extends SettingsRepository {
	public string $mode               = SettingsRepository::MODE_AUTO_INJECT;
	/** @var string[] */
	public array $covered_post_types = array( 'post' );

	public function display_mode(): string {
		return $this->mode;
	}

	public function covers_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->covered_post_types, true );
	}
}

final class ContentFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_type = "post"; }' );
		}

		Functions\when( 'has_shortcode' )->justReturn( false );
		Functions\when( 'in_the_loop' )->justReturn( true );
		Functions\when( 'is_main_query' )->justReturn( true );
		Functions\when( 'is_singular' )->justReturn( true );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_post(): \WP_Post {
		$p            = new \WP_Post();
		$p->ID        = 1;
		$p->post_type = 'post';
		return $p;
	}

	public function test_does_not_inject_when_display_mode_is_off(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer       = new StubRenderer();
		$shortcode      = new Shortcode( $renderer );
		$settings       = new StubSettings();
		$settings->mode = SettingsRepository::MODE_OFF;

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'original', $filter->maybe_append( 'original' ) );
		$this->assertSame( 0, $renderer->calls );
	}

	public function test_does_not_inject_when_display_mode_is_shortcode_only(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer       = new StubRenderer();
		$shortcode      = new Shortcode( $renderer );
		$settings       = new StubSettings();
		$settings->mode = SettingsRepository::MODE_SHORTCODE;

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'original', $filter->maybe_append( 'original' ) );
		$this->assertSame( 0, $renderer->calls );
	}

	public function test_does_not_inject_when_not_in_main_query(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'is_main_query' )->justReturn( false );

		$renderer  = new StubRenderer();
		$shortcode = new Shortcode( $renderer );
		$settings  = new StubSettings();

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'original', $filter->maybe_append( 'original' ) );
		$this->assertSame( 0, $renderer->calls );
	}

	public function test_does_not_inject_when_post_type_not_covered(): void {
		$post            = $this->fake_post();
		$post->post_type = 'unhandled';
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer                       = new StubRenderer();
		$shortcode                      = new Shortcode( $renderer );
		$settings                       = new StubSettings();
		$settings->covered_post_types   = array( 'post' );

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'original', $filter->maybe_append( 'original' ) );
		$this->assertSame( 0, $renderer->calls );
	}

	public function test_injects_widget_when_all_conditions_match(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer        = new StubRenderer();
		$renderer->reply = '<section>x</section>';
		$shortcode       = new Shortcode( $renderer );
		$settings        = new StubSettings();

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'original<section>x</section>', $filter->maybe_append( 'original' ) );
		$this->assertSame( 1, $renderer->calls );
	}

	public function test_suppresses_injection_when_shortcode_is_in_body(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );
		Functions\when( 'has_shortcode' )->justReturn( true );

		$renderer  = new StubRenderer();
		$shortcode = new Shortcode( $renderer );
		$settings  = new StubSettings();

		$filter = new ContentFilter( $renderer, $shortcode, $settings );
		$this->assertSame( 'with [semantic_posts]', $filter->maybe_append( 'with [semantic_posts]' ) );
		$this->assertSame( 0, $renderer->calls );
	}

	public function test_does_not_double_inject_when_shortcode_already_rendered(): void {
		$post = $this->fake_post();
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer  = new StubRenderer();
		$shortcode = new Shortcode( $renderer );
		$shortcode->mark_rendered( $post->ID );

		$settings = new StubSettings();
		$filter   = new ContentFilter( $renderer, $shortcode, $settings );

		$this->assertSame( 'original', $filter->maybe_append( 'original' ) );
		$this->assertSame( 0, $renderer->calls );
	}
}
