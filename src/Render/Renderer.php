<?php
/**
 * Orchestrate resolver → template → filter/action surface (ADR-0007).
 *
 * All filter and action names live here in one place so the public contract
 * is auditable from a single file.
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

use WP_Post;

/**
 * Translate a resolved item list into final HTML.
 */
class Renderer {

	/**
	 * Strategy used to resolve which post IDs to render.
	 *
	 * @var SourceResolver
	 */
	private SourceResolver $resolver;

	/**
	 * Re-entrancy guard. WP's `wp_trim_excerpt` runs `the_content` on related-post
	 * bodies, which would recurse into us mid-template. We bump this counter for
	 * the duration of a render() call so any nested call early-exits.
	 *
	 * @var int
	 */
	private static int $rendering_depth = 0;

	/**
	 * @param SourceResolver $resolver Source resolver to delegate item selection to.
	 */
	public function __construct( SourceResolver $resolver ) {
		$this->resolver = $resolver;
	}

	/**
	 * True when a render() call is already in flight on this request — callers
	 * (ContentFilter, Shortcode) can short-circuit before doing any work.
	 */
	public static function is_rendering(): bool {
		return self::$rendering_depth > 0;
	}

	/**
	 * Render the related-posts widget for $post and return HTML.
	 *
	 * Returns an empty string when the resolver yields no items or when a
	 * render() call is already in flight (re-entrancy guard).
	 *
	 * @param WP_Post $post  Source post.
	 * @param int     $count Number of items the resolver should target.
	 */
	public function render( WP_Post $post, int $count = 5 ): string {
		if ( self::$rendering_depth > 0 ) {
			return '';
		}
		++self::$rendering_depth;
		try {
			return $this->render_inner( $post, $count );
		} finally {
			--self::$rendering_depth;
		}
	}

	/**
	 * Inner render — assumes the re-entrancy guard is already incremented.
	 *
	 * @param WP_Post $post  Source post.
	 * @param int     $count Number of items the resolver should target.
	 */
	private function render_inner( WP_Post $post, int $count ): string {
		do_action( 'semantic_posts_before_render', $post );

		$resolved = $this->resolver->resolve( $post, $count );
		if ( empty( $resolved['items'] ) ) {
			do_action( 'semantic_posts_after_render', $post );
			return '';
		}

		$context = array(
			'sp_item_ids'       => $resolved['items'],
			'sp_data_source'    => (string) $resolved['data_source'],
			'sp_item_sources'   => isset( $resolved['item_sources'] ) && is_array( $resolved['item_sources'] )
				? $resolved['item_sources']
				: array(),
			'sp_heading_text'   => (string) apply_filters(
				'semantic_posts_heading_text',
				__( 'You might also like', 'semantic-posts' ),
				$post
			),
			'sp_excerpt_length' => (int) apply_filters( 'semantic_posts_excerpt_length', 160, $post ),
			'sp_item_classes'   => (array) apply_filters( 'semantic_posts_item_classes', array(), $post ),
			'sp_thumbnail_size' => (string) apply_filters( 'semantic_posts_thumbnail_size', 'large', $post ),
		);

		// Optional min_score gate (only meaningful in semantic source; surfaced now per ADR-0007).
		$min_score = (float) apply_filters( 'semantic_posts_min_score', 0.0, $post );
		if ( $min_score > 0.0 && SourceResolver::SOURCE_CATEGORY === $context['sp_data_source'] ) {
			// Category fallback has no score — min_score blocks it.
			do_action( 'semantic_posts_after_render', $post );
			return '';
		}

		$template = $this->locate_template();
		$html     = $this->capture_template( $template, $context );

		$html = (string) apply_filters( 'semantic_posts_render_html', $html, $post, $resolved );

		do_action( 'semantic_posts_after_render', $post );
		return $html;
	}

	/**
	 * Resolve which template file to include, honoring theme overrides and the
	 * `semantic_posts_template_path` filter.
	 */
	private function locate_template(): string {
		$plugin_template = SEMANTIC_POSTS_DIR . 'templates/related-posts.php';
		$located         = locate_template( array( 'semantic-posts/related-posts.php' ) );

		if ( '' !== $located ) {
			$plugin_template = $located;
		}

		$paths = (array) apply_filters(
			'semantic_posts_template_path',
			array( $plugin_template ),
			$plugin_template
		);

		foreach ( $paths as $path ) {
			if ( is_string( $path ) && file_exists( $path ) ) {
				return $path;
			}
		}
		return SEMANTIC_POSTS_DIR . 'templates/related-posts.php';
	}

	/**
	 * Include the template inside an output buffer and return the captured HTML.
	 *
	 * @param string              $template_path Absolute path of the template to include.
	 * @param array<string,mixed> $context       Variables exposed to the template.
	 */
	private function capture_template( string $template_path, array $context ): string {
		ob_start();
		// Expose context as local variables — `extract` is acceptable here because
		// the context array is constructed in this class with known keys.
		extract( $context, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		include $template_path;
		return (string) ob_get_clean();
	}
}
