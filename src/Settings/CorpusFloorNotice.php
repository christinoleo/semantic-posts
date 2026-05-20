<?php
/**
 * Dismissible "<50 indexable posts" notice.
 *
 * Renders when the corpus is below the Similarity Graph's useful floor — the
 * user can still bulk-index, but related-post quality will be visibly weaker
 * until they add more content. Per-user dismissal via user meta so each admin
 * only sees the warning once.
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

final class CorpusFloorNotice {

	private const FLOOR = 50;

	/**
	 * Settings repository used to read the configured post types.
	 *
	 * @var SettingsRepository
	 */
	private SettingsRepository $settings;

	/**
	 * @param SettingsRepository $settings Settings repository.
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Render the notice when the corpus floor is breached and the current user
	 * hasn't dismissed it. Called from the admin_notices hook (only on the
	 * SemanticPosts settings page — see SettingsPage::register_menu).
	 */
	public function maybe_render(): void {
		$user_id = (int) get_current_user_id();
		if ( $user_id <= 0 ) {
			return;
		}
		$dismissed = get_user_meta( $user_id, AjaxHandler::NOTICE_USER_META_KEY, true );
		if ( '1' === (string) $dismissed ) {
			return;
		}
		if ( $this->indexable_count() >= self::FLOOR ) {
			return;
		}
		?>
		<div class="notice notice-warning is-dismissible" data-sp-notice="floor">
			<p>
				<strong><?php esc_html_e( 'SemanticPosts:', 'semantic-posts' ); ?></strong>
				<?php esc_html_e( 'Your indexable corpus has fewer than 50 posts. Related-post quality will improve as the corpus grows — this notice is informational and can be dismissed.', 'semantic-posts' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Total of `publish` posts across the configured post types.
	 */
	private function indexable_count(): int {
		$total = 0;
		foreach ( $this->settings->post_types() as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}
		return $total;
	}
}
