<?php
/**
 * `[semantic_posts]` shortcode handler.
 *
 * Per acceptance: count attribute clamps to 3..10; multiple shortcodes in one
 * post → first wins, rest are no-ops; presence of the shortcode in the body
 * suppresses ContentFilter auto-injection (handled in ContentFilter, not here).
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

/**
 * Shortcode adapter.
 */
final class Shortcode {

	public const TAG     = 'semantic_posts';
	public const MIN     = 3;
	public const MAX     = 10;
	public const DEFAULT = 5;

	/**
	 * Renderer used to produce widget HTML.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Tracks whether this post has already rendered the widget so subsequent
	 * shortcodes (and auto-injection) are suppressed.
	 *
	 * @var array<int,bool>
	 */
	private array $rendered_for = array();

	/**
	 * @param Renderer $renderer Renderer to delegate widget HTML production to.
	 */
	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	/**
	 * Whether the widget has already been emitted for $post_id this request.
	 *
	 * @param int $post_id Post ID to check.
	 */
	public function already_rendered_for( int $post_id ): bool {
		return ! empty( $this->rendered_for[ $post_id ] );
	}

	/**
	 * Mark a post as rendered (called by ContentFilter when it injects).
	 *
	 * @param int $post_id Post ID to flag.
	 */
	public function mark_rendered( int $post_id ): void {
		$this->rendered_for[ $post_id ] = true;
	}

	/**
	 * @param array<string,mixed>|string $atts Shortcode attributes (WP passes
	 *                                          empty string when no attrs).
	 */
	public function render( $atts = array() ): string {
		// Skip recursive entries (e.g. shortcode called from inside our own template
		// during get_the_excerpt — Renderer's static guard catches both this
		// and the_content entry path).
		if ( Renderer::is_rendering() ) {
			return '';
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return '';
		}

		if ( $this->already_rendered_for( $post->ID ) ) {
			return '';
		}

		if ( ! is_array( $atts ) ) {
			$atts = array();
		}
		$atts = shortcode_atts(
			array( 'count' => self::DEFAULT ),
			$atts,
			self::TAG
		);

		$count = (int) $atts['count'];
		if ( $count < self::MIN ) {
			$count = self::MIN;
		}
		if ( $count > self::MAX ) {
			$count = self::MAX;
		}

		$html = $this->renderer->render( $post, $count );
		if ( '' !== $html ) {
			$this->mark_rendered( $post->ID );
		}
		return $html;
	}
}
