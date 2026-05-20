<?php
/**
 * Admin AJAX surface for the Settings page (TB-12).
 *
 * Endpoints (all wp_ajax_* — logged-in only, plus an additional
 * `manage_options` cap check inside each handler):
 *
 *   semantic_posts_cost_preview         — live cost calc on model/count change.
 *   semantic_posts_validate_api_key     — validates + stores OpenAI key.
 *   semantic_posts_start_indexing       — kicks off ColdStartProcessor.
 *   semantic_posts_progress             — current cold-start snapshot.
 *   semantic_posts_wipe_and_reindex     — wipes all `_sp_*` postmeta + restarts.
 *   semantic_posts_dismiss_floor_notice — dismisses the <50-corpus notice.
 *
 * Boundary discipline (AR-12):
 *   - Every handler verifies the nonce + capability BEFORE reading any input.
 *   - All input is sanitized at this layer (sanitize_text_field, intval, etc.).
 *   - Responses go through wp_send_json_success/error so the JS sees consistent JSON.
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Indexing\Wiper;
use SemanticPosts\Security\ApiKeyStorage;
use SemanticPosts\Security\ApiKeyValidator;

final class AjaxHandler {

	public const NONCE_ACTION         = 'semantic_posts_admin_ajax';
	public const NOTICE_USER_META_KEY = '_sp_floor_notice_dismissed';

	public const ACTION_COST_PREVIEW   = 'semantic_posts_cost_preview';
	public const ACTION_VALIDATE_KEY   = 'semantic_posts_validate_api_key';
	public const ACTION_START_INDEXING = 'semantic_posts_start_indexing';
	public const ACTION_PROGRESS       = 'semantic_posts_progress';
	public const ACTION_WIPE_REINDEX   = 'semantic_posts_wipe_and_reindex';
	public const ACTION_DISMISS_FLOOR  = 'semantic_posts_dismiss_floor_notice';

	/** @var SettingsRepository */
	private SettingsRepository $settings;
	/** @var CostEstimator */
	private CostEstimator $estimator;
	/** @var ApiKeyStorage */
	private ApiKeyStorage $key_storage;
	/** @var ApiKeyValidator */
	private ApiKeyValidator $key_validator;
	/** @var ColdStartProcessor */
	private ColdStartProcessor $cold_start;
	/** @var Wiper */
	private Wiper $wiper;
	/** @var UnindexedQueue */
	private UnindexedQueue $unindexed;

	/**
	 * @param SettingsRepository $settings      Settings repository.
	 * @param CostEstimator      $estimator     Cost estimator for the live preview.
	 * @param ApiKeyStorage      $key_storage   API key store (encrypted).
	 * @param ApiKeyValidator    $key_validator Validates candidate keys via test call.
	 * @param ColdStartProcessor $cold_start    Cold-start orchestrator.
	 * @param Wiper              $wiper         Postmeta wipe for the re-index flow.
	 * @param UnindexedQueue     $unindexed     Read-side queue (not currently used by
	 *                                          handlers — reserved for richer progress
	 *                                          payloads in TB-13).
	 */
	public function __construct(
		SettingsRepository $settings,
		CostEstimator $estimator,
		ApiKeyStorage $key_storage,
		ApiKeyValidator $key_validator,
		ColdStartProcessor $cold_start,
		Wiper $wiper,
		UnindexedQueue $unindexed
	) {
		$this->settings      = $settings;
		$this->estimator     = $estimator;
		$this->key_storage   = $key_storage;
		$this->key_validator = $key_validator;
		$this->cold_start    = $cold_start;
		$this->wiper         = $wiper;
		$this->unindexed     = $unindexed;
	}

	/**
	 * Authorize the request. Returns true when nonce + capability check out;
	 * otherwise emits `wp_send_json_error` and returns false.
	 */
	private function authorize(): bool {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden.' ), 403 );
			return false;
		}
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => 'Invalid nonce.' ), 403 );
			return false;
		}
		return true;
	}

	/** Live cost-preview endpoint. */
	public function handle_cost_preview(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		// Nonce is verified above in authorize() via check_ajax_referer.
		$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['model'] ) ) : $this->settings->embedding_model(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$count = $this->indexable_corpus_count();
		$out   = $this->estimator->estimate( $count, $model );
		wp_send_json_success( $out );
	}

	/** Validate-and-store the API key. */
	public function handle_validate_api_key(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		// Nonce is verified above in authorize() via check_ajax_referer.
		$key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['api_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result = $this->key_validator->validate( $key );
		if ( ! $result['ok'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ), 400 );
			return;
		}
		$this->key_storage->store( $key );
		wp_send_json_success(
			array(
				'message' => $result['message'],
				'masked'  => $this->mask( $key ),
			)
		);
	}

	/** Kick off ColdStartProcessor::start(). */
	public function handle_start_indexing(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		if ( null === $this->key_storage->retrieve() ) {
			wp_send_json_error( array( 'message' => 'API key not configured.' ), 400 );
			return;
		}
		$started = $this->cold_start->start();
		if ( ! $started ) {
			wp_send_json_success(
				array(
					'message'  => 'No posts pending indexing.',
					'progress' => $this->cold_start->progress(),
				)
			);
			return;
		}
		wp_send_json_success(
			array(
				'message'  => 'Indexing started.',
				'progress' => $this->cold_start->progress(),
			)
		);
	}

	/** Return the current cold-start progress snapshot. */
	public function handle_progress(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		wp_send_json_success( $this->cold_start->progress() );
	}

	/** Wipe all `_sp_*` postmeta and restart cold-start. */
	public function handle_wipe_and_reindex(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		if ( null === $this->key_storage->retrieve() ) {
			wp_send_json_error( array( 'message' => 'API key not configured.' ), 400 );
			return;
		}
		$rows    = $this->wiper->wipe_embeddings();
		$started = $this->cold_start->start();
		wp_send_json_success(
			array(
				'message'      => 'Wiped ' . $rows . ' rows; re-indexing started.',
				'rows_deleted' => $rows,
				'restarted'    => $started,
				'progress'     => $this->cold_start->progress(),
			)
		);
	}

	/** Dismiss the corpus-floor admin notice for the current user. */
	public function handle_dismiss_floor_notice(): void {
		if ( ! $this->authorize() ) {
			return;
		}
		$user_id = (int) get_current_user_id();
		if ( $user_id > 0 ) {
			update_user_meta( $user_id, self::NOTICE_USER_META_KEY, '1' );
		}
		wp_send_json_success();
	}

	/**
	 * Count of posts eligible for indexing — covered post types, published,
	 * not password-protected. The cost preview is anchored on this.
	 */
	private function indexable_corpus_count(): int {
		// UnindexedQueue counts only NOT-embedded posts; for a corpus-wide cost
		// preview we want the total of indexable posts, so use wp_count_posts.
		$total = 0;
		foreach ( $this->settings->post_types() as $pt ) {
			$counts = wp_count_posts( $pt );
			if ( is_object( $counts ) && isset( $counts->publish ) ) {
				$total += (int) $counts->publish;
			}
		}
		return $total;
	}

	/**
	 * Return a masked preview of the key, suitable for inline UI display.
	 *
	 * @param string $key Plaintext key.
	 */
	private function mask( string $key ): string {
		$len = strlen( $key );
		if ( $len <= 8 ) {
			return str_repeat( '*', $len );
		}
		return substr( $key, 0, 4 ) . str_repeat( '*', $len - 8 ) . substr( $key, -4 );
	}
}
