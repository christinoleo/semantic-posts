<?php
/**
 * Surfaces a "Reindex all" CTA when the most recent VerificationPass detected
 * drift above the threshold.
 *
 * Per-admin dismissal is intentionally NOT supported: drift means the graph is
 * stale and the only fix is to re-index, so the notice must stay sticky until
 * the user takes action (which resets MRD on the next pass).
 *
 * @package SemanticPosts\Verification
 */

declare( strict_types=1 );

namespace SemanticPosts\Verification;

use SemanticPosts\Indexing\StateRepository;

final class DriftNotice {

	/** @var StateRepository */
	private StateRepository $state;
	/** @var VerificationPass */
	private VerificationPass $verification;

	/**
	 * @param StateRepository  $state        State repo (last_mrd source).
	 * @param VerificationPass $verification Verification helper for the live threshold.
	 */
	public function __construct( StateRepository $state, VerificationPass $verification ) {
		$this->state        = $state;
		$this->verification = $verification;
	}

	/**
	 * Render the notice when the latest MRD is over the drift threshold.
	 * Wired by Bootstrap to `admin_notices`.
	 */
	public function maybe_render(): void {
		$state = $this->state->read();
		$mrd   = (float) ( $state['verification']['last_mrd'] ?? 0 );
		if ( $mrd < $this->verification->threshold() ) {
			return;
		}
		?>
		<div class="notice notice-warning" data-sp-notice="mrd-drift">
			<p>
				<strong><?php esc_html_e( 'SemanticPosts:', 'semantic-posts' ); ?></strong>
				<?php
				printf(
					/* translators: 1: observed MRD, 2: threshold. */
					esc_html__( 'Related-posts graph drift detected (MRD = %1$.2f, threshold = %2$.2f). Re-indexing the corpus is recommended to refresh recommendations.', 'semantic-posts' ),
					(float) $mrd,
					(float) $this->verification->threshold()
				);
				?>
				<a href="<?php echo esc_url( admin_url( 'options-general.php?page=semantic-posts' ) ); ?>"><?php esc_html_e( 'Open settings →', 'semantic-posts' ); ?></a>
			</p>
		</div>
		<?php
	}
}
