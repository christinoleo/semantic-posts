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

use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Embeddings\OpenAIProvider;
use SemanticPosts\Indexing\CleanupRouter;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\DirtyQueue;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\MemoryGuard;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\SavePostHandler;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\TickProcessor;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Lifecycle\BackupFilter;
use SemanticPosts\Render\ContentFilter;
use SemanticPosts\Render\Renderer;
use SemanticPosts\Render\Shortcode;
use SemanticPosts\Render\SourceResolver;
use SemanticPosts\Security\ApiKeyStorage;
use SemanticPosts\Settings\SettingsPage;
use SemanticPosts\Settings\SettingsRepository;

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

		$settings  = new SettingsRepository();
		$resolver  = new SourceResolver();
		$renderer  = new Renderer( $resolver );
		$shortcode = new Shortcode( $renderer );
		$content   = new ContentFilter( $renderer, $shortcode, $settings );
		$page      = new SettingsPage( $settings );
		$backup    = new BackupFilter();

		// Indexing pipeline (TB-05/06/07/08).
		$builder         = new IndexableTextBuilder();
		$rate_limiter    = new RateLimiter();
		$hash_detector   = new HashDiffDetector( $builder );
		$state           = new StateRepository();
		$key_storage     = new ApiKeyStorage();
		$provider        = new OpenAIProvider( $key_storage );
		$neighbors       = new NeighborStore();
		$crawler         = new Crawler( $neighbors );
		$embed_job       = new EmbedJob( $provider, $builder, $rate_limiter, $hash_detector, $state, $crawler );
		$cleanup         = new CleanupRouter( $neighbors, $hash_detector );
		$save_handler    = new SavePostHandler( $hash_detector, $cleanup, $embed_job );
		$dirty_queue     = new DirtyQueue();
		$memory_guard    = new MemoryGuard();
		$unindexed_queue = new UnindexedQueue();
		$cold_start      = new ColdStartProcessor( $unindexed_queue, $embed_job, $crawler, $state, $memory_guard );
		$tick_processor  = new TickProcessor( $dirty_queue, $embed_job, $memory_guard, $state, $cold_start );

		add_action( 'admin_menu', array( $page, 'register_menu' ) );
		add_filter( 'the_content', array( $content, 'maybe_append' ), 20 );
		add_filter( 'semantic_posts_exclude_from_backup', array( $backup, 'default_excluded' ) );
		add_shortcode( Shortcode::TAG, array( $shortcode, 'render' ) );

		// Indexing hooks (TB-07).
		add_action( 'save_post', array( $save_handler, 'on_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( $save_handler, 'on_transition_post_status' ), 10, 3 );
		add_action( 'wp_trash_post', array( $save_handler, 'on_trash' ) );
		add_action( TickProcessor::HOOK, array( $tick_processor, 'run' ) );
		add_action( SavePostHandler::IMMEDIATE_EMBED_HOOK, array( $save_handler, 'handle_immediate_embed' ) );

		// Subsequent slices (TB-08+) extend this with crawler/observability hooks.
	}

	/**
	 * Load the plugin text domain. Wired via the init hook above.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'semantic-posts', false, dirname( plugin_basename( SEMANTIC_POSTS_FILE ) ) . '/languages' );
	}
}
