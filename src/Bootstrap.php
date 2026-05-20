<?php
/**
 * Plugin Bootstrap — the single owner of `add_action` and `add_filter` registration.
 *
 * AR-* invariant: NO other class in src/ calls add_action/add_filter directly.
 * This keeps the hook graph readable from one place and is enforced by a CI grep.
 *
 * @package SemanticPosts
 */

declare( strict_types=1 );

namespace SemanticPosts;

/**
 * Plugin Bootstrap. See file header for the single-owner invariant.
 */
final class Bootstrap {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Tracks whether registerHooks has already run so it stays idempotent.
	 *
	 * @var bool
	 */
	private bool $registered = false;

	/**
	 * Return the singleton instance, lazily creating it.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register all WordPress hooks consumed by the plugin.
	 *
	 * Idempotent — calling twice is a no-op so re-instantiation in tests
	 * doesn't double-register.
	 */
	public function registerHooks(): void {
		if ( $this->registered ) {
			return;
		}
		$this->registered = true;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Subsequent slices (TB-05+) extend this with embedding/crawler/render hooks.
	}

	/**
	 * Load the plugin text domain. Wired via the init hook above.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'semantic-posts', false, dirname( plugin_basename( SEMANTIC_POSTS_FILE ) ) . '/languages' );
	}
}
