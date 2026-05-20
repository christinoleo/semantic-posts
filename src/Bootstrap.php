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
use SemanticPosts\Indexing\CronRegistration;
use SemanticPosts\Indexing\DirtyQueue;
use SemanticPosts\Indexing\EmbedJob;
use SemanticPosts\Indexing\HashDiffDetector;
use SemanticPosts\Indexing\MemoryGuard;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Indexing\SavePostHandler;
use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\TickProcessor;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Indexing\Wiper;
use SemanticPosts\Lifecycle\BackupFilter;
use SemanticPosts\Observability\EVRegistry;
use SemanticPosts\Observability\ObservabilityPanel;
use SemanticPosts\Observability\StateMetrics;
use SemanticPosts\Ranking\ModeFactory;
use SemanticPosts\Render\ContentFilter;
use SemanticPosts\Render\Renderer;
use SemanticPosts\Render\Shortcode;
use SemanticPosts\Render\SourceResolver;
use SemanticPosts\Security\ApiKeyStorage;
use SemanticPosts\Security\ApiKeyValidator;
use SemanticPosts\Settings\AjaxHandler;
use SemanticPosts\Settings\CorpusFloorNotice;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsPage;
use SemanticPosts\Settings\SettingsRepository;
use SemanticPosts\Verification\DriftNotice;
use SemanticPosts\Verification\VerificationPass;
use SemanticPosts\Embeddings\Vector;
use SemanticPosts\CLI\Commands as CliCommands;

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

		$settings     = new SettingsRepository();
		$state        = new StateRepository();
		$metrics      = new StateMetrics( $state );
		$resolver     = new SourceResolver( $metrics );
		$renderer     = new Renderer( $resolver );
		$shortcode    = new Shortcode( $renderer );
		$content      = new ContentFilter( $renderer, $shortcode, $settings );
		$key_storage  = new ApiKeyStorage();
		$backup       = new BackupFilter();
		$floor_notice = new CorpusFloorNotice( $settings );

		// Indexing pipeline (TB-05/06/07/08).
		$builder         = new IndexableTextBuilder();
		$rate_limiter    = new RateLimiter();
		$hash_detector   = new HashDiffDetector( $builder );
		$provider        = new OpenAIProvider( $key_storage );
		$neighbors       = new NeighborStore();
		$mode_factory    = new ModeFactory();
		$crawler         = new Crawler(
			$neighbors,
			null,
			null,
			null,
			static function () use ( $settings, $mode_factory ) {
				return $mode_factory->make( $settings->ranking_mode() );
			}
		);
		$embed_job       = new EmbedJob( $provider, $builder, $rate_limiter, $hash_detector, $state, $crawler, $metrics );
		$cleanup         = new CleanupRouter( $neighbors, $hash_detector );
		$save_handler    = new SavePostHandler( $hash_detector, $cleanup, $embed_job );
		$dirty_queue     = new DirtyQueue();
		$memory_guard    = new MemoryGuard();
		$unindexed_queue = new UnindexedQueue();
		$cold_start      = new ColdStartProcessor( $unindexed_queue, $embed_job, $crawler, $state, $memory_guard );

		// TB-14 verification.
		$verification   = new VerificationPass(
			$state,
			$neighbors,
			static fn(): int => time(),
			static function ( int $size ) {
				return self::random_indexed_post_ids( $size );
			},
			static function () {
				return self::all_indexed_post_ids();
			},
			static function ( int $post_id ) {
				$raw = get_post_meta( $post_id, Vector::POSTMETA_KEY, true );
				if ( ! is_string( $raw ) || '' === $raw ) {
					return null;
				}
				$decoded = Vector::decode( $raw );
				return 0 === $decoded->getSize() ? null : $decoded;
			}
		);
		$tick_processor = new TickProcessor( $dirty_queue, $embed_job, $memory_guard, $state, $cold_start, $metrics, $verification );
		$drift_notice   = new DriftNotice( $state, $verification );

		// TB-12 admin surface.
		$estimator     = new CostEstimator();
		$key_validator = new ApiKeyValidator();
		$wiper         = new Wiper( $state );
		$ev_registry   = new EVRegistry( $settings );
		$panel         = new ObservabilityPanel( $metrics, $state, $unindexed_queue, $key_storage, $ev_registry );
		$page          = new SettingsPage( $settings, $key_storage, $panel );
		$ajax          = new AjaxHandler(
			$settings,
			$estimator,
			$key_storage,
			$key_validator,
			$cold_start,
			$wiper,
			$unindexed_queue,
			$tick_processor,
			$dirty_queue,
			$hash_detector,
			$state,
			$verification
		);

		add_action( 'admin_menu', array( $page, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $page, 'enqueue_assets' ) );
		add_action( 'admin_notices', array( $floor_notice, 'maybe_render' ) );
		add_action( 'admin_notices', array( $drift_notice, 'maybe_render' ) );
		add_filter( 'cron_schedules', array( CronRegistration::class, 'register_intervals' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'the_content', array( $content, 'maybe_append' ), 20 );
		add_filter( 'semantic_posts_exclude_from_backup', array( $backup, 'default_excluded' ) );
		add_shortcode( Shortcode::TAG, array( $shortcode, 'render' ) );

		// Indexing hooks (TB-07).
		add_action( 'save_post', array( $save_handler, 'on_save_post' ), 10, 2 );
		add_action( 'transition_post_status', array( $save_handler, 'on_transition_post_status' ), 10, 3 );
		add_action( 'wp_trash_post', array( $save_handler, 'on_trash' ) );
		add_action( TickProcessor::HOOK, array( $tick_processor, 'run' ) );
		add_action( SavePostHandler::IMMEDIATE_EMBED_HOOK, array( $save_handler, 'handle_immediate_embed' ) );

		// TB-12 AJAX endpoints (logged-in only). Each handler re-checks cap + nonce.
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_COST_PREVIEW, array( $ajax, 'handle_cost_preview' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_VALIDATE_KEY, array( $ajax, 'handle_validate_api_key' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_START_INDEXING, array( $ajax, 'handle_start_indexing' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_PROGRESS, array( $ajax, 'handle_progress' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_WIPE_REINDEX, array( $ajax, 'handle_wipe_and_reindex' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_DISMISS_FLOOR, array( $ajax, 'handle_dismiss_floor_notice' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_RUN_INDEXING_NOW, array( $ajax, 'handle_run_indexing_now' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_RETRY_FAILED, array( $ajax, 'handle_retry_failed' ) );
		add_action( 'wp_ajax_' . AjaxHandler::ACTION_RUN_VERIFICATION_NOW, array( $ajax, 'handle_run_verification_now' ) );

		// TB-15: WP-CLI surface (registered only when running under WP-CLI).
		if ( defined( 'WP_CLI' ) && constant( 'WP_CLI' ) && class_exists( '\\WP_CLI' ) ) {
			$cli = new CliCommands(
				$cold_start,
				$wiper,
				$tick_processor,
				$verification,
				$state,
				$hash_detector,
				$metrics
			);
			\WP_CLI::add_command( 'semantic-posts', $cli );
		}
	}

	/**
	 * Load the plugin text domain. Wired via the init hook above.
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain( 'semantic-posts', false, dirname( plugin_basename( SEMANTIC_POSTS_FILE ) ) . '/languages' );
	}

	/**
	 * Return every post ID that currently has an `_sp_embedding` row. Used by
	 * the TB-14 brute-force comparison.
	 *
	 * @return int[]
	 */
	private static function all_indexed_post_ids(): array {
		if ( ! class_exists( \WP_Query::class ) ) {
			return array();
		}
		$query = new \WP_Query(
			array(
				'post_status'            => 'publish',
				'post_type'              => 'any',
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Vector::POSTMETA_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);
		return array_map( 'intval', (array) $query->posts );
	}

	/**
	 * Pick `$size` random indexed post IDs. Lightweight implementation: re-uses
	 * `all_indexed_post_ids` and shuffles in-PHP. The verification pass only
	 * runs weekly so the extra read is fine.
	 *
	 * @param int $size Sample size.
	 * @return int[]
	 */
	private static function random_indexed_post_ids( int $size ): array {
		$all = self::all_indexed_post_ids();
		if ( count( $all ) <= $size ) {
			shuffle( $all );
			return $all;
		}
		shuffle( $all );
		return array_slice( $all, 0, $size );
	}
}
