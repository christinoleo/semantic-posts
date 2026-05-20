<?php
/**
 * Append the related-posts widget to single-post content.
 *
 * Conditions:
 *   - Only fires inside the_content filter on the main loop.
 *   - Only on `is_single()` views.
 *   - Only when post_type is enabled in settings.
 *   - Only when display_mode === 'auto-inject'.
 *   - Skipped when the post body already contains the [semantic_posts] shortcode
 *     (dedup) — that case is owned by Shortcode.
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

use SemanticPosts\Settings\SettingsRepository;

/**
 * the_content filter integration.
 */
final class ContentFilter {

	/**
	 * Renderer used to produce widget HTML.
	 *
	 * @var Renderer
	 */
	private Renderer $renderer;

	/**
	 * Shortcode tracker — consulted to dedup against in-body shortcodes.
	 *
	 * @var Shortcode
	 */
	private Shortcode $shortcode;

	/**
	 * Settings repository — consulted for display_mode and post_types.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;


	/**
	 * @param Renderer           $renderer  Widget renderer.
	 * @param Shortcode          $shortcode Shortcode handler (dedup signal).
	 * @param SettingsRepository $settings  Settings repository.
	 */
	public function __construct( Renderer $renderer, Shortcode $shortcode, SettingsRepository $settings ) {
		$this->renderer  = $renderer;
		$this->shortcode = $shortcode;
		$this->settings  = $settings;
	}

	/**
	 * the_content filter callback. Returns content unchanged when conditions
	 * don't match; appends the widget HTML otherwise.
	 *
	 * @param string $content Original post content.
	 */
	public function maybe_append( string $content ): string {
		// Re-entrancy guard lives on Renderer (static depth counter) — nested
		// the_content calls (e.g. inside wp_trim_excerpt for related-post excerpts)
		// see is_rendering()=true and skip cleanly.
		if ( Renderer::is_rendering() ) {
			return $content;
		}

		if ( ! $this->should_inject() ) {
			return $content;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return $content;
		}

		if ( $this->shortcode->already_rendered_for( $post->ID ) ) {
			return $content;
		}

		if ( has_shortcode( $content, Shortcode::TAG ) ) {
			// Shortcode in body → shortcode handler will render; suppress auto-injection.
			return $content;
		}

		$widget = $this->renderer->render( $post, Shortcode::DEFAULT );
		if ( '' === $widget ) {
			return $content;
		}

		$this->shortcode->mark_rendered( $post->ID );
		return $content . $widget;
	}

	/**
	 * Gate for maybe_append — combines display_mode, query context, and post-type settings.
	 */
	private function should_inject(): bool {
		if ( SettingsRepository::MODE_AUTO_INJECT !== $this->settings->display_mode() ) {
			return false;
		}
		if ( ! function_exists( 'is_singular' ) || ! function_exists( 'is_main_query' ) ) {
			return false;
		}
		if ( ! is_singular() ) {
			return false;
		}
		if ( ! in_the_loop() || ! is_main_query() ) {
			return false;
		}

		$post = get_post();
		if ( ! $post instanceof \WP_Post ) {
			return false;
		}

		return $this->settings->covers_post_type( $post->post_type );
	}
}
