<?php
/**
 * Renders the observability panel (TB-13) — appended to the Settings screen.
 *
 * Surfaces the 24h Metrics summary, recent-ticks list, graceful-restore banner,
 * no-API-key banner, and admin maintenance buttons (Run indexing now, Retry
 * failed, Reindex all). The actions themselves are AJAX endpoints in
 * AjaxHandler — this class just renders the surface.
 *
 * @package SemanticPosts\Observability
 */

declare( strict_types=1 );

namespace SemanticPosts\Observability;

use SemanticPosts\Indexing\StateRepository;
use SemanticPosts\Indexing\UnindexedQueue;
use SemanticPosts\Security\ApiKeyStorage;

final class ObservabilityPanel {

	/** Render the banner if ≥5% of Recommendable Posts are unindexed. */
	public const RESTORE_RATIO_THRESHOLD = 0.05;

	/** @var Metrics */
	private Metrics $metrics;
	/** @var StateRepository */
	private StateRepository $state;
	/** @var UnindexedQueue */
	private UnindexedQueue $unindexed;
	/** @var ApiKeyStorage */
	private ApiKeyStorage $key_storage;
	/** @var EVRegistry|null */
	private ?EVRegistry $ev_registry;

	/**
	 * @param Metrics         $metrics     Read-side metrics for the 24h summary.
	 * @param StateRepository $state       State repo (used for "indexed total" estimate).
	 * @param UnindexedQueue  $unindexed   Pending-post queue (banner ratio numerator).
	 * @param ApiKeyStorage   $key_storage Key store (no-key banner gate).
	 * @param EVRegistry|null $ev_registry Algorithm-constants registry (TB-16). Optional for back-compat.
	 */
	public function __construct(
		Metrics $metrics,
		StateRepository $state,
		UnindexedQueue $unindexed,
		ApiKeyStorage $key_storage,
		?EVRegistry $ev_registry = null
	) {
		$this->metrics     = $metrics;
		$this->state       = $state;
		$this->unindexed   = $unindexed;
		$this->key_storage = $key_storage;
		$this->ev_registry = $ev_registry;
	}

	/**
	 * Render the panel + banners. Called from the SettingsPage::render() tail.
	 */
	public function render(): void {
		$summary = $this->metrics->summary24h();
		$pending = $this->unindexed->count();
		$total   = $pending + $this->indexed_total_estimate();
		$key_set = is_string( $this->key_storage->retrieve() );

		if ( ! $key_set ) {
			?>
			<div class="notice notice-warning" data-sp-notice="no-api-key">
				<p><?php esc_html_e( 'Configure your API key to enable semantic recommendations.', 'semantic-posts' ); ?></p>
			</div>
			<?php
		}

		if ( $total > 0 && ( $pending / $total ) >= self::RESTORE_RATIO_THRESHOLD ) {
			$indexed = max( 0, $total - $pending );
			?>
			<div class="notice notice-info" data-sp-notice="graceful-restore">
				<p>
					<?php
					printf(
						/* translators: 1: indexed count, 2: total Recommendable Posts. */
						esc_html__( 'Reindexing in progress. %1$d / %2$d posts indexed.', 'semantic-posts' ),
						(int) $indexed,
						(int) $total
					);
					?>
				</p>
			</div>
			<?php
		}

		?>
		<hr>
		<h2><?php esc_html_e( 'Observability (last 24h)', 'semantic-posts' ); ?></h2>
		<table class="widefat fixed striped semantic-posts-observability">
			<tbody>
				<tr>
					<th><?php esc_html_e( 'Embedding calls', 'semantic-posts' ); ?></th>
					<td><?php echo esc_html( (string) $summary['embedding_calls'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Estimated cost (USD)', 'semantic-posts' ); ?></th>
					<td>$<?php echo esc_html( number_format( (float) $summary['embedding_cost_usd'], 4 ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Posts in dirty queue', 'semantic-posts' ); ?></th>
					<td><?php echo esc_html( (string) $summary['queue_size'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Failed posts', 'semantic-posts' ); ?></th>
					<td>
						<?php echo esc_html( (string) $summary['failed_count'] ); ?>
						<?php if ( $summary['failed_count'] > 0 ) : ?>
							<button type="button" class="button" id="semantic-posts-retry-failed"><?php esc_html_e( 'Retry failed', 'semantic-posts' ); ?></button>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Last cron tick', 'semantic-posts' ); ?></th>
					<td>
						<?php
						if ( null === $summary['last_tick_ts'] ) {
							esc_html_e( '— never run', 'semantic-posts' );
						} else {
							echo esc_html(
								sprintf(
									'%s (%s)',
									gmdate( 'Y-m-d H:i:s', (int) $summary['last_tick_ts'] ),
									(string) $summary['last_tick_outcome']
								)
							);
						}
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Render-path queries (avg / max)', 'semantic-posts' ); ?></th>
					<td><?php echo esc_html( $summary['render_query_avg'] . ' / ' . $summary['render_query_max'] ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Peak memory (MB, 24h)', 'semantic-posts' ); ?></th>
					<td><?php echo esc_html( (string) $summary['peak_memory_mb'] ); ?></td>
				</tr>
				<?php
				$verification = is_array( $this->state->read()['verification'] ?? null ) ? $this->state->read()['verification'] : array();
				$last_mrd     = isset( $verification['last_mrd'] ) ? (float) $verification['last_mrd'] : null;
				$last_run     = isset( $verification['last_run'] ) ? (int) $verification['last_run'] : null;
				?>
				<tr>
					<th><?php esc_html_e( 'Last verification MRD', 'semantic-posts' ); ?></th>
					<td>
						<?php
						if ( null === $last_mrd ) {
							esc_html_e( '— never run', 'semantic-posts' );
						} else {
							echo esc_html( sprintf( '%0.2f', $last_mrd ) );
						}
						if ( null !== $last_run ) {
							echo ' · ' . esc_html( gmdate( 'Y-m-d H:i:s', $last_run ) );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Recent ticks', 'semantic-posts' ); ?></h3>
		<?php if ( empty( $summary['recent_ticks'] ) ) : ?>
			<p><em><?php esc_html_e( 'No ticks recorded yet.', 'semantic-posts' ); ?></em></p>
		<?php else : ?>
			<ul class="semantic-posts-recent-ticks">
				<?php foreach ( array_reverse( $summary['recent_ticks'] ) as $tick ) : ?>
					<li>
						<?php
						echo esc_html(
							sprintf(
								'%s · processed=%d · outcome=%s%s',
								gmdate( 'Y-m-d H:i:s', (int) ( $tick['ts'] ?? 0 ) ),
								(int) ( $tick['processed'] ?? 0 ),
								(string) ( $tick['outcome'] ?? '?' ),
								! empty( $tick['halted_for_memory'] ) ? ' (memory halt)' : ''
							)
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( null !== $this->ev_registry ) : ?>
			<h3><?php esc_html_e( 'Algorithm constants (EV registry)', 'semantic-posts' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Read-only mirror of the EV registry from architecture.md. "Source" reflects whether the value is still the default, was filtered at runtime, or is tied to a Setting.', 'semantic-posts' ); ?></p>
			<table class="widefat fixed striped semantic-posts-ev-registry">
				<thead>
					<tr>
						<th><?php esc_html_e( 'EV', 'semantic-posts' ); ?></th>
						<th><?php esc_html_e( 'Name', 'semantic-posts' ); ?></th>
						<th><?php esc_html_e( 'Current', 'semantic-posts' ); ?></th>
						<th><?php esc_html_e( 'Source', 'semantic-posts' ); ?></th>
						<th><?php esc_html_e( 'Revisit trigger', 'semantic-posts' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $this->ev_registry->entries() as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['id'] ); ?></td>
							<td><?php echo esc_html( $row['name'] ); ?></td>
							<td><code><?php echo esc_html( $this->format_value( $row['current'] ) ); ?></code></td>
							<td><?php echo esc_html( $row['source'] ); ?></td>
							<td><?php echo esc_html( $row['revisit'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Maintenance', 'semantic-posts' ); ?></h3>
		<p>
			<button type="button" class="button" id="semantic-posts-run-tick-now"><?php esc_html_e( 'Run indexing now', 'semantic-posts' ); ?></button>
			<button type="button" class="button" id="semantic-posts-run-verification-now"><?php esc_html_e( 'Run verification now', 'semantic-posts' ); ?></button>
			<span class="semantic-posts-maint-status" id="semantic-posts-maint-status" aria-live="polite"></span>
		</p>
		<?php
	}

	/**
	 * Conservative estimate of how many posts are already indexed. Used only
	 * for the graceful-restore banner ratio — exact precision isn't required.
	 */
	/**
	 * Compact a scalar for the EV table.
	 *
	 * @param mixed $value Value to render.
	 */
	private function format_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return '—';
		}
		if ( is_float( $value ) ) {
			return rtrim( rtrim( number_format( $value, 4 ), '0' ), '.' );
		}
		if ( is_array( $value ) ) {
			return wp_json_encode( $value );
		}
		return (string) $value;
	}

	/**
	 * Conservative count of currently-indexed posts. Denominator for the
	 * graceful-restore banner ratio — exact precision isn't needed.
	 */
	private function indexed_total_estimate(): int {
		$state   = $this->state->read();
		$metrics = is_array( $state['metrics'] ?? null ) ? $state['metrics'] : array();
		return (int) ( $metrics['succeeded'] ?? 0 );
	}
}
