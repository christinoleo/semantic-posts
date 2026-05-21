<?php
/**
 * Enqueue the minimal frontend stylesheet for the related-posts widget.
 *
 * Structural-only CSS (grid, hover, image sizing) so themes still own colors
 * and typography. Sites can opt out entirely via the
 * `semantic_posts_enqueue_styles` filter.
 *
 * @package SemanticPosts\Render
 */

declare( strict_types=1 );

namespace SemanticPosts\Render;

defined( 'ABSPATH' ) || exit;

final class FrontendAssets {

	private const HANDLE = 'semantic-posts-frontend';

	/**
	 * Registered on `wp_enqueue_scripts`. Skipped in admin/feeds/AMP-ish requests.
	 */
	public function enqueue(): void {
		if ( ! (bool) apply_filters( 'semantic_posts_enqueue_styles', true ) ) {
			return;
		}
		if ( ! defined( 'SEMANTIC_POSTS_URL' ) || ! defined( 'SEMANTIC_POSTS_VERSION' ) ) {
			return;
		}
		wp_enqueue_style(
			self::HANDLE,
			SEMANTIC_POSTS_URL . 'assets/css/frontend.css',
			array(),
			SEMANTIC_POSTS_VERSION
		);
	}
}
