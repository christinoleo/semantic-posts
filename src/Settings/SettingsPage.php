<?php
/**
 * Admin → Settings → SemanticPosts page.
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

/**
 * Renders the settings form + handles save.
 */
final class SettingsPage {

	public const MENU_SLUG = 'semantic-posts';
	public const NONCE     = 'semantic_posts_settings_save';

	/**
	 * Settings repository (persistence + sanitization).
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $repo;

	/**
	 * @param SettingsRepository $repo Settings repository.
	 */
	public function __construct( SettingsRepository $repo ) {
		$this->repo = $repo;
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
	 * Render the settings form and process submissions.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Forbidden.', 'semantic-posts' ) );
		}

		$saved = false;
		if ( isset( $_POST['semantic_posts_settings_submit'] ) ) {
			check_admin_referer( self::NONCE );
			$raw = array();
			if ( isset( $_POST['semantic_posts_settings'] ) && is_array( $_POST['semantic_posts_settings'] ) ) {
				// Sanitize at the input boundary via map_deep + sanitize_text_field; the repo
				// then re-validates against the known schema.
				$unslashed = wp_unslash( $_POST['semantic_posts_settings'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on next line.
				$raw       = map_deep( $unslashed, 'sanitize_text_field' );
			}
			$this->repo->save( is_array( $raw ) ? $raw : array() );
			$saved = true;
		}

		$current     = $this->repo->all();
		$public_cpts = get_post_types( array( 'public' => true ), 'objects' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SemanticPosts Settings', 'semantic-posts' ); ?></h1>
			<?php if ( $saved ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Settings saved.', 'semantic-posts' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>

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

				<p>
					<button type="submit" name="semantic_posts_settings_submit" class="button button-primary">
						<?php esc_html_e( 'Save changes', 'semantic-posts' ); ?>
					</button>
				</p>
			</form>
		</div>
		<?php
	}
}
