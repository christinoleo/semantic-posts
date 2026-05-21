<?php
/**
 * Plugin Name:       SemanticPosts
 * Plugin URI:        https://github.com/christinoleo/semantic-posts
 * Description:       Related posts via semantic embeddings. Precomputed at index time, served from postmeta cache.
 * Version:           0.2.2
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            Leonardo Christino
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       semantic-posts
 * Update URI:        https://github.com/christinoleo/semantic-posts
 *
 * @package SemanticPosts
 */

defined( 'ABSPATH' ) || exit;

define( 'SEMANTIC_POSTS_VERSION', '0.2.2' );
define( 'SEMANTIC_POSTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEMANTIC_POSTS_URL', plugin_dir_url( __FILE__ ) );
define( 'SEMANTIC_POSTS_FILE', __FILE__ );

$semantic_posts_autoload = SEMANTIC_POSTS_DIR . 'vendor/autoload.php';
if ( file_exists( $semantic_posts_autoload ) ) {
	require_once $semantic_posts_autoload;
}

/**
 * Freemius SDK bootstrap. Must initialize BEFORE Bootstrap::registerHooks so
 * that `sp_fs()->is_paying()` is callable from gate code paths.
 *
 * Public IDs only — `plugin_id` and `plugin_public_key` are safe to commit
 * (analogous to Stripe `pk_live_…`). The signing secret lives on Freemius's
 * servers; license responses are verified via signature, not the plugin's
 * possession of any secret.
 */
if ( ! function_exists( 'sp_fs' ) && ! ( defined( 'SEMANTIC_POSTS_BYPASS_FREEMIUS' ) && SEMANTIC_POSTS_BYPASS_FREEMIUS ) ) {
	function sp_fs() {
		global $sp_fs;
		if ( ! isset( $sp_fs ) ) {
			$freemius_start = SEMANTIC_POSTS_DIR . 'freemius/start.php';
			if ( ! file_exists( $freemius_start ) ) {
				return null;
			}
			require_once $freemius_start;
			$sp_fs = fs_dynamic_init(
				array(
					'id'             => '30197',
					'slug'           => 'semantic-posts',
					'type'           => 'plugin',
					'public_key'     => 'pk_67c2047c0f13ef4399650e5bf10b9',
					// is_premium: true marks this codebase as already containing
					// the Pro features (gated via `if (is_paying())` checks). The
					// free tier is enforced by our own PaywallGate, not by
					// Freemius's free/premium split-build mechanism. This
					// suppresses the "Download latest pro version" CTA that
					// otherwise tries to fetch a separate zip from Freemius's
					// deployment system (which we don't use — distribution is
					// via GitHub Releases).
					'is_premium'     => true,
					// Without a paid plan the plugin still functions (free tier
					// gating is our responsibility, not Freemius's).
					'is_premium_only' => false,
					'has_addons'     => false,
					'has_paid_plans' => true,
					// anonymous_mode: don't force the opt-in modal on activation;
					// the plugin works immediately and users can opt in to analytics
					// later from Settings → SemanticPosts.
					'anonymous_mode' => true,
					'menu'           => array(
						'slug'    => 'semantic-posts',
						'support' => false,
					),
					'is_live'        => true,
				)
			);
		}
		return $sp_fs;
	}
	sp_fs();
	do_action( 'sp_fs_loaded' );
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
		if ( class_exists( \SemanticPosts\Indexing\CronRegistration::class ) ) {
			$frequency = 'hourly';
			if ( class_exists( \SemanticPosts\Settings\SettingsRepository::class ) ) {
				$frequency = ( new \SemanticPosts\Settings\SettingsRepository() )->cron_frequency();
			}
			\SemanticPosts\Indexing\CronRegistration::activate( $frequency );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		if ( class_exists( \SemanticPosts\Indexing\CronRegistration::class ) ) {
			\SemanticPosts\Indexing\CronRegistration::deactivate();
		}
	}
);

// AR invariant: Bootstrap is the only place add_action/add_filter live. The
// main file just kicks it off — WP loads this file before plugins_loaded fires,
// so registering hooks here is safe for any action that runs `init` or later.
if ( class_exists( \SemanticPosts\Bootstrap::class ) ) {
	\SemanticPosts\Bootstrap::instance()->registerHooks();
}
