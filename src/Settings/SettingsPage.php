<?php
/**
 * Admin → Settings → SemanticPosts page.
 *
 * Single-page surface for everything an admin can configure:
 *   - Post types covered by the widget
 *   - Display mode (auto-inject / shortcode / off)
 *   - Ranking mode (most-relevant / fresh-first / diverse-mix)
 *   - OpenAI API key (masked, validated via test embed call)
 *   - Embedding model (text-embedding-3-small | -large)
 *   - Related-post count (3–10)
 *   - Quality-bounded mode + min_items + score_threshold
 *   - Cron frequency (hourly / 6h / daily)
 *   - Live cost preview (AJAX)
 *   - Bulk-index controls (start, wipe-and-reindex, progress bar)
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

use SemanticPosts\Indexing\CronRegistration;
use SemanticPosts\Observability\ObservabilityPanel;
use SemanticPosts\Security\ApiKeyStorage;

/**
 * Renders the settings form + handles save.
 */
final class SettingsPage {

	public const MENU_SLUG        = 'semantic-posts';
	public const NONCE            = 'semantic_posts_settings_save';
	public const PAGE_HOOK_SUFFIX = 'settings_page_semantic-posts';

	/**
	 * @var SettingsRepository
	 */
	private SettingsRepository $repo;

	/**
	 * @var ApiKeyStorage
	 */
	private ApiKeyStorage $key_storage;

	/**
	 * @var ObservabilityPanel|null
	 */
	private ?ObservabilityPanel $panel;

	/**
	 * @param SettingsRepository      $repo        Settings repository.
	 * @param ApiKeyStorage           $key_storage API key storage adapter (used to
	 *                                             decide whether the placeholder
	 *                                             signals "key on file").
	 * @param ObservabilityPanel|null $panel       Observability surface appended below
	 *                                             the form (TB-13). Optional — back-compat.
	 */
	public function __construct( SettingsRepository $repo, ApiKeyStorage $key_storage, ?ObservabilityPanel $panel = null ) {
		$this->repo        = $repo;
		$this->key_storage = $key_storage;
		$this->panel       = $panel;
	}

	/**
	 * Register the Settings menu entry. Called from Bootstrap via the admin_menu hook.
	 */
	public function register_menu(): void {
		add_options_page(
			__( 'SemanticPosts', 'semantic-posts' ),
			__( 'SemanticPosts', 'semantic-posts' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Enqueue admin JS + CSS on the settings page. Wired by Bootstrap to
	 * `admin_enqueue_scripts` so it never runs on other admin screens.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public function enqueue_assets( string $hook_suffix ): void {
		if ( self::PAGE_HOOK_SUFFIX !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'semantic-posts-admin',
			SEMANTIC_POSTS_URL . 'assets/css/admin-settings.css',
			array(),
			SEMANTIC_POSTS_VERSION
		);
		wp_enqueue_script(
			'semantic-posts-admin',
			SEMANTIC_POSTS_URL . 'assets/js/admin-settings.js',
			array(),
			SEMANTIC_POSTS_VERSION,
			true
		);
		wp_localize_script(
			'semantic-posts-admin',
			'SemanticPostsAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( AjaxHandler::NONCE_ACTION ),
				'actions'     => array(
					'costPreview'     => AjaxHandler::ACTION_COST_PREVIEW,
					'validateKey'     => AjaxHandler::ACTION_VALIDATE_KEY,
					'startIndexing'   => AjaxHandler::ACTION_START_INDEXING,
					'progress'        => AjaxHandler::ACTION_PROGRESS,
					'wipeReindex'     => AjaxHandler::ACTION_WIPE_REINDEX,
					'dismissFloor'    => AjaxHandler::ACTION_DISMISS_FLOOR,
					'runIndexingNow'  => AjaxHandler::ACTION_RUN_INDEXING_NOW,
					'retryFailed'     => AjaxHandler::ACTION_RETRY_FAILED,
					'runVerification' => AjaxHandler::ACTION_RUN_VERIFICATION_NOW,
				),
				'i18n'        => array(
					/* translators: %s is the estimated USD cost. */
					'confirmWipe'   => __( 'Changing the embedding model will regenerate embeddings for every indexed post. This costs approximately $%s. Continue?', 'semantic-posts' ),
					/* translators: %s is the estimated USD cost. */
					'confirmStart'  => __( 'Start indexing the corpus? Estimated cost: $%s.', 'semantic-posts' ),
					/* translators: 1: number of indexed posts, 2: total posts. */
					'progressLabel' => __( 'Indexed %1$d / %2$d', 'semantic-posts' ),
					'idleLabel'     => __( 'Idle.', 'semantic-posts' ),
					'startingLabel' => __( 'Starting…', 'semantic-posts' ),
					'completeLabel' => __( 'Indexing complete.', 'semantic-posts' ),
					'validating'    => __( 'Validating…', 'semantic-posts' ),
					'keySaved'      => __( 'API key saved.', 'semantic-posts' ),
					'estimating'    => __( 'Estimating cost…', 'semantic-posts' ),
				),
				'pricingNote' => __( 'Estimates assume 500 tokens per post. Actual cost may vary.', 'semantic-posts' ),
			)
		);
	}

	/**
	 * Render the settings form and process submissions.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'semantic-posts' ) );
		}

		$saved              = false;
		$model_changed      = false;
		$frequency_changed  = false;
		$previous_model     = $this->repo->embedding_model();
		$previous_frequency = $this->repo->cron_frequency();

		if ( isset( $_POST['semantic_posts_settings_submit'] ) ) {
			check_admin_referer( self::NONCE );
			$raw = array();
			if ( isset( $_POST['semantic_posts_settings'] ) && is_array( $_POST['semantic_posts_settings'] ) ) {
				$unslashed = wp_unslash( $_POST['semantic_posts_settings'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line.
				$raw       = map_deep( $unslashed, 'sanitize_text_field' );
			}
			$result            = $this->repo->save( is_array( $raw ) ? $raw : array() );
			$saved             = true;
			$model_changed     = ( $previous_model !== $result['embedding_model'] );
			$frequency_changed = ( $previous_frequency !== $result['cron_frequency'] );
			if ( $frequency_changed ) {
				CronRegistration::reschedule( $result['cron_frequency'] );
			}
		}

		$current     = $this->repo->all();
		$public_cpts = get_post_types( array( 'public' => true ), 'objects' );
		$stored_key  = $this->key_storage->retrieve();
		$key_set     = is_string( $stored_key ) && '' !== $stored_key;
		?>
		<div class="wrap semantic-posts-settings">
			<h1><?php echo esc_html__( 'SemanticPosts Settings', 'semantic-posts' ); ?></h1>

			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'semantic-posts' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $model_changed ) : ?>
				<div class="notice notice-warning" data-sp-notice="model-changed">
					<p>
						<strong><?php esc_html_e( 'Embedding model changed.', 'semantic-posts' ); ?></strong>
						<?php esc_html_e( 'Existing embeddings will keep working with the old model until you wipe and re-index. Use "Wipe and re-index" below to switch the whole corpus to the new model.', 'semantic-posts' ); ?>
					</p>
				</div>
			<?php endif; ?>

			<form method="post" action="" id="semantic-posts-settings-form">
				<?php wp_nonce_field( self::NONCE ); ?>

				<h2><?php esc_html_e( 'OpenAI API key', 'semantic-posts' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Stored encrypted at rest. Click "Validate & save" to verify the key with a test call before saving.', 'semantic-posts' ); ?>
				</p>
				<p>
					<input type="password" id="semantic-posts-api-key" name="semantic_posts_api_key" autocomplete="off" class="regular-text" placeholder="<?php echo $key_set ? esc_attr__( '•••• key on file ••••', 'semantic-posts' ) : 'sk-...'; ?>">
					<button type="button" class="button" id="semantic-posts-validate-key"><?php esc_html_e( 'Validate & save', 'semantic-posts' ); ?></button>
					<span class="semantic-posts-key-status" id="semantic-posts-key-status" aria-live="polite"></span>
				</p>

				<h2><?php esc_html_e( 'Post types', 'semantic-posts' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'Choose which post types get a related-posts widget. Commerce CPTs (WooCommerce products, etc.) are best left off — related products are handled by the commerce plugin.', 'semantic-posts' ); ?>
				</p>
				<fieldset>
					<?php
					foreach ( $public_cpts as $cpt ) :
						$slug    = $cpt->name;
						$label   = $cpt->labels->singular_name ?? $slug;
						$checked = in_array( $slug, $current['post_types'], true ) ? 'checked' : '';
						?>
						<label>
							<input type="checkbox" name="semantic_posts_settings[post_types][]" value="<?php echo esc_attr( $slug ); ?>" <?php echo esc_attr( $checked ); ?>>
							<?php echo esc_html( $label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Display mode', 'semantic-posts' ); ?></h2>
				<fieldset>
					<?php
					$modes = array(
						SettingsRepository::MODE_AUTO_INJECT => __( 'Auto-inject after the_content', 'semantic-posts' ),
						SettingsRepository::MODE_SHORTCODE => __( 'Shortcode only ([semantic_posts])', 'semantic-posts' ),
						SettingsRepository::MODE_OFF       => __( 'Off', 'semantic-posts' ),
					);
					foreach ( $modes as $value => $label ) :
						$checked = ( $value === $current['display_mode'] ) ? 'checked' : '';
						?>
						<label>
							<input type="radio" name="semantic_posts_settings[display_mode]" value="<?php echo esc_attr( $value ); ?>" <?php echo esc_attr( $checked ); ?>>
							<?php echo esc_html( $label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Ranking mode', 'semantic-posts' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'How the plugin orders the related-posts widget. Featured (first card) is always the highest-relevance match regardless of mode.', 'semantic-posts' ); ?>
				</p>
				<fieldset>
					<?php
					$rankings = array(
						\SemanticPosts\Ranking\Mode::MOST_RELEVANT => __( 'Most relevant — pure semantic match (default).', 'semantic-posts' ),
						\SemanticPosts\Ranking\Mode::FRESH_FIRST   => __( 'Fresh first — recency-weighted: recent posts surface ahead of equally-relevant older ones.', 'semantic-posts' ),
						\SemanticPosts\Ranking\Mode::DIVERSE_MIX   => __( 'Diverse mix — MMR: prefers topical variety after the featured card.', 'semantic-posts' ),
					);
					foreach ( $rankings as $value => $label ) :
						$checked = ( $value === $current['ranking_mode'] ) ? 'checked' : '';
						?>
						<label>
							<input type="radio" name="semantic_posts_settings[ranking_mode]" value="<?php echo esc_attr( $value ); ?>" <?php echo esc_attr( $checked ); ?>>
							<?php echo esc_html( $label ); ?>
						</label><br>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Embedding model', 'semantic-posts' ); ?></h2>
				<p>
					<select name="semantic_posts_settings[embedding_model]" id="semantic-posts-model">
						<option value="<?php echo esc_attr( SettingsRepository::MODEL_SMALL ); ?>" <?php selected( $current['embedding_model'], SettingsRepository::MODEL_SMALL ); ?>>
							<?php esc_html_e( 'OpenAI text-embedding-3-small ($0.020 / 1M tokens)', 'semantic-posts' ); ?>
						</option>
						<option value="<?php echo esc_attr( SettingsRepository::MODEL_LARGE ); ?>" <?php selected( $current['embedding_model'], SettingsRepository::MODEL_LARGE ); ?>>
							<?php esc_html_e( 'OpenAI text-embedding-3-large ($0.130 / 1M tokens)', 'semantic-posts' ); ?>
						</option>
					</select>
				</p>

				<h2><?php esc_html_e( 'Related-post count', 'semantic-posts' ); ?></h2>
				<p>
					<input type="number" name="semantic_posts_settings[related_count]" id="semantic-posts-count" min="3" max="10" value="<?php echo esc_attr( (string) $current['related_count'] ); ?>">
					<span class="description"><?php esc_html_e( '3–10 items rendered per widget.', 'semantic-posts' ); ?></span>
				</p>

				<h2><?php esc_html_e( 'Quality-bounded mode', 'semantic-posts' ); ?></h2>
				<p>
					<label>
						<input type="checkbox" id="semantic-posts-quality-bounded" name="semantic_posts_settings[quality_bounded]" value="1" <?php checked( $current['quality_bounded'] ); ?>>
						<?php esc_html_e( 'Hide widget when too few high-quality matches are available.', 'semantic-posts' ); ?>
					</label>
				</p>
				<div class="semantic-posts-quality-fields" <?php echo $current['quality_bounded'] ? '' : 'hidden'; ?>>
					<p>
						<label>
							<?php esc_html_e( 'Minimum items:', 'semantic-posts' ); ?>
							<input type="number" name="semantic_posts_settings[min_items]" min="1" max="10" value="<?php echo esc_attr( (string) $current['min_items'] ); ?>">
						</label>
					</p>
					<p>
						<label>
							<?php esc_html_e( 'Score threshold (0.0–1.0):', 'semantic-posts' ); ?>
							<input type="number" name="semantic_posts_settings[score_threshold]" min="0" max="1" step="0.01" value="<?php echo esc_attr( (string) $current['score_threshold'] ); ?>">
						</label>
					</p>
				</div>

				<h2><?php esc_html_e( 'Cron frequency', 'semantic-posts' ); ?></h2>
				<p>
					<select name="semantic_posts_settings[cron_frequency]">
						<option value="<?php echo esc_attr( SettingsRepository::FREQUENCY_HOURLY ); ?>" <?php selected( $current['cron_frequency'], SettingsRepository::FREQUENCY_HOURLY ); ?>><?php esc_html_e( 'Hourly', 'semantic-posts' ); ?></option>
						<option value="<?php echo esc_attr( SettingsRepository::FREQUENCY_SIX_HOURS ); ?>" <?php selected( $current['cron_frequency'], SettingsRepository::FREQUENCY_SIX_HOURS ); ?>><?php esc_html_e( 'Every 6 hours', 'semantic-posts' ); ?></option>
						<option value="<?php echo esc_attr( SettingsRepository::FREQUENCY_DAILY ); ?>" <?php selected( $current['cron_frequency'], SettingsRepository::FREQUENCY_DAILY ); ?>><?php esc_html_e( 'Daily', 'semantic-posts' ); ?></option>
					</select>
				</p>

				<h2><?php esc_html_e( 'Cost preview', 'semantic-posts' ); ?></h2>
				<div class="semantic-posts-cost" id="semantic-posts-cost" aria-live="polite">
					<?php esc_html_e( 'Loading estimate…', 'semantic-posts' ); ?>
				</div>

				<p>
					<button type="submit" name="semantic_posts_settings_submit" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'semantic-posts' ); ?>
					</button>
				</p>
			</form>

			<hr>

			<h2><?php esc_html_e( 'Bulk indexing', 'semantic-posts' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Run a one-shot pass over the entire corpus. Resumable — safe to navigate away mid-run.', 'semantic-posts' ); ?>
			</p>
			<p>
				<button type="button" class="button button-primary" id="semantic-posts-start"><?php esc_html_e( 'Start indexing', 'semantic-posts' ); ?></button>
				<button type="button" class="button" id="semantic-posts-wipe-reindex"><?php esc_html_e( 'Wipe & re-index', 'semantic-posts' ); ?></button>
				<span class="semantic-posts-bulk-status" id="semantic-posts-bulk-status" aria-live="polite"></span>
			</p>
			<div class="semantic-posts-progress" id="semantic-posts-progress">
				<div class="semantic-posts-progress-bar"><span class="semantic-posts-progress-fill" style="width:0%"></span></div>
				<div class="semantic-posts-progress-label" id="semantic-posts-progress-label"></div>
			</div>

			<?php
			if ( null !== $this->panel ) {
				$this->panel->render();
			}
			?>
		</div>
		<?php
	}
}
