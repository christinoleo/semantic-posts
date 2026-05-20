<?php
/**
 * Plugin Name:       SemanticPosts
 * Plugin URI:        https://github.com/christinoleo/semantic-posts
 * Description:       Related posts via semantic embeddings. Precomputed at index time, served from postmeta cache.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Leonardo Christino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       semantic-posts
 *
 * @package SemanticPosts
 */

defined( 'ABSPATH' ) || exit;

define( 'SEMANTIC_POSTS_VERSION', '0.1.0' );
define( 'SEMANTIC_POSTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEMANTIC_POSTS_URL', plugin_dir_url( __FILE__ ) );
define( 'SEMANTIC_POSTS_FILE', __FILE__ );

$semantic_posts_autoload = SEMANTIC_POSTS_DIR . 'vendor/autoload.php';
if ( file_exists( $semantic_posts_autoload ) ) {
	require_once $semantic_posts_autoload;
}

register_activation_hook(
	__FILE__,
	static function () {
		if ( version_compare( PHP_VERSION, '8.0', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'SemanticPosts requires PHP 8.0 or higher.', 'semantic-posts' ),
				esc_html__( 'Plugin activation error', 'semantic-posts' ),
				array( 'back_link' => true )
			);
		}
	}
);

// AR invariant: Bootstrap is the only place add_action/add_filter live. The
// main file just kicks it off — WP loads this file before plugins_loaded fires,
// so registering hooks here is safe for any action that runs `init` or later.
if ( class_exists( \SemanticPosts\Bootstrap::class ) ) {
	\SemanticPosts\Bootstrap::instance()->registerHooks();
}
