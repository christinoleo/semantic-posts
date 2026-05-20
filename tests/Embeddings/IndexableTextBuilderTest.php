<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Embeddings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\IndexableTextBuilder;

final class IndexableTextBuilderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( '
				class WP_Post {
					public int $ID = 0;
					public string $post_title = "";
					public string $post_excerpt = "";
					public string $post_content = "";
					public string $post_type = "post";
				}
			' );
		}

		// Minimal HTML-strip; preserves text and shortcode tokens.
		Functions\when( 'wp_strip_all_tags' )->alias(
			static fn( $html ) => trim( strip_tags( (string) $html ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function make_post( string $title, string $content, string $excerpt = '' ): \WP_Post {
		$post                = new \WP_Post();
		$post->ID            = 1;
		$post->post_title    = $title;
		$post->post_content  = $content;
		$post->post_excerpt  = $excerpt;
		return $post;
	}

	public function test_title_is_repeated_three_times_at_start(): void {
		$post = $this->make_post( 'Hello World', 'Body text here.' );
		$out  = ( new IndexableTextBuilder() )->build( $post );

		// Count exact-match occurrences of "Hello World" with whitespace before/around.
		$matches = preg_match_all( '/Hello World/', $out );
		$this->assertSame( 3, $matches, 'Title must appear 3× (ADR-0001).' );
	}

	public function test_manual_excerpt_is_included(): void {
		$post = $this->make_post( 'T', 'Body.', 'Author-curated excerpt.' );
		$out  = ( new IndexableTextBuilder() )->build( $post );
		$this->assertStringContainsString( 'Author-curated excerpt.', $out );
	}

	public function test_empty_excerpt_is_omitted(): void {
		$post = $this->make_post( 'T', 'Body.', '' );
		$out  = ( new IndexableTextBuilder() )->build( $post );

		// No extra double-newline gap should leak from the missing excerpt.
		$this->assertStringNotContainsString( "\n\n\n\n", $out );
	}

	public function test_whitespace_only_excerpt_is_treated_as_empty(): void {
		$post = $this->make_post( 'T', 'Body.', "   \n  " );
		$out  = ( new IndexableTextBuilder() )->build( $post );
		$this->assertStringNotContainsString( "\n\n\n\n", $out );
	}

	public function test_html_is_stripped_from_content(): void {
		$post = $this->make_post( 'T', '<p>Hello <strong>world</strong></p>' );
		$out  = ( new IndexableTextBuilder() )->build( $post );
		$this->assertStringContainsString( 'Hello world', $out );
		$this->assertStringNotContainsString( '<p>', $out );
		$this->assertStringNotContainsString( '<strong>', $out );
	}

	public function test_shortcodes_are_preserved_as_raw_tokens(): void {
		$post = $this->make_post( 'T', 'Before [contact-form id=5] after.' );
		$out  = ( new IndexableTextBuilder() )->build( $post );

		// Shortcode must NOT be rendered — raw [tag] token survives.
		$this->assertStringContainsString( '[contact-form id=5]', $out );
	}

	public function test_truncation_at_max_words(): void {
		// Build a content body of MAX_WORDS + 100 words.
		$body_words = array_fill( 0, IndexableTextBuilder::MAX_WORDS + 100, 'word' );
		$post       = $this->make_post( 'T', implode( ' ', $body_words ) );

		$out   = ( new IndexableTextBuilder() )->build( $post );
		$words = preg_split( '/\s+/', $out, -1, PREG_SPLIT_NO_EMPTY );
		$this->assertLessThanOrEqual( IndexableTextBuilder::MAX_WORDS, count( $words ) );
	}

	public function test_short_content_is_not_truncated(): void {
		$post = $this->make_post( 'T', 'Just a short body.' );
		$out  = ( new IndexableTextBuilder() )->build( $post );
		$this->assertStringContainsString( 'Just a short body.', $out );
	}

	public function test_whitespace_in_content_is_collapsed(): void {
		$post = $this->make_post( 'T', "Para one.\n\n\n\n\nPara two." );
		$out  = ( new IndexableTextBuilder() )->build( $post );

		// Collapsed inside cleaned_content section.
		$this->assertStringContainsString( 'Para one. Para two.', $out );
	}

	public function test_returns_string_with_paragraph_separators(): void {
		$post = $this->make_post( 'Title', 'Body.' );
		$out  = ( new IndexableTextBuilder() )->build( $post );

		// Title repeated 3× separated by \n\n, then body separated by \n\n.
		$this->assertStringStartsWith( "Title\n\nTitle\n\nTitle\n\nBody.", $out );
	}
}
