<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Render;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Render\Renderer;
use SemanticPosts\Render\Shortcode;
use SemanticPosts\Render\SourceResolver;

/**
 * Hand-rolled test double for Renderer — PHPUnit can't mock `final` classes.
 * Captures every render() call and returns a scripted HTML payload.
 */
final class RecordingRenderer extends Renderer {
	/** @var array<int,array{post:\WP_Post,count:int}> */
	public array $calls = array();
	public string $next_html = '<section>x</section>';

	public function __construct() {
		parent::__construct( new SourceResolver() );
	}

	public function render( \WP_Post $post, int $count = 5 ): string {
		$this->calls[] = array( 'post' => $post, 'count' => $count );
		return $this->next_html;
	}
}

final class ShortcodeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		Functions\when( 'shortcode_atts' )->alias(
			static function ( $defaults, $atts ) {
				return is_array( $atts ) ? array_merge( $defaults, $atts ) : $defaults;
			}
		);

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_type = "post"; }' );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function fake_post( int $id ): \WP_Post {
		$post            = new \WP_Post();
		$post->ID        = $id;
		$post->post_type = 'post';
		return $post;
	}

	public function test_render_returns_empty_when_no_post_in_context(): void {
		Functions\when( 'get_post' )->justReturn( null );

		$renderer  = new RecordingRenderer();
		$shortcode = new Shortcode( $renderer );
		$this->assertSame( '', $shortcode->render() );
		$this->assertSame( array(), $renderer->calls );
	}

	public function test_render_clamps_count_below_min_to_three(): void {
		$post = $this->fake_post( 7 );
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer  = new RecordingRenderer();
		$shortcode = new Shortcode( $renderer );
		$shortcode->render( array( 'count' => '1' ) );

		$this->assertCount( 1, $renderer->calls );
		$this->assertSame( 3, $renderer->calls[0]['count'] );
	}

	public function test_render_clamps_count_above_max_to_ten(): void {
		$post = $this->fake_post( 8 );
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer  = new RecordingRenderer();
		$shortcode = new Shortcode( $renderer );
		$shortcode->render( array( 'count' => '999' ) );

		$this->assertCount( 1, $renderer->calls );
		$this->assertSame( 10, $renderer->calls[0]['count'] );
	}

	public function test_first_shortcode_wins_subsequent_are_noop(): void {
		$post = $this->fake_post( 9 );
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer            = new RecordingRenderer();
		$renderer->next_html = '<section>first</section>';
		$shortcode           = new Shortcode( $renderer );

		$first  = $shortcode->render();
		$second = $shortcode->render();
		$third  = $shortcode->render();

		$this->assertSame( '<section>first</section>', $first );
		$this->assertSame( '', $second );
		$this->assertSame( '', $third );
		$this->assertCount( 1, $renderer->calls );
	}

	public function test_mark_rendered_is_per_post_id(): void {
		$post_a = $this->fake_post( 10 );
		$post_b = $this->fake_post( 11 );

		$renderer  = new RecordingRenderer();
		$shortcode = new Shortcode( $renderer );

		$this->assertFalse( $shortcode->already_rendered_for( $post_a->ID ) );
		$shortcode->mark_rendered( $post_a->ID );
		$this->assertTrue( $shortcode->already_rendered_for( $post_a->ID ) );
		$this->assertFalse( $shortcode->already_rendered_for( $post_b->ID ) );
	}

	public function test_empty_html_does_not_mark_rendered(): void {
		$post = $this->fake_post( 12 );
		Functions\when( 'get_post' )->justReturn( $post );

		$renderer            = new RecordingRenderer();
		$renderer->next_html = '';
		$shortcode           = new Shortcode( $renderer );

		$shortcode->render();
		$shortcode->render();

		$this->assertFalse( $shortcode->already_rendered_for( $post->ID ) );
		$this->assertCount( 2, $renderer->calls );
	}
}
