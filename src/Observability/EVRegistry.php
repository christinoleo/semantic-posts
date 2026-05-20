<?php
/**
 * Empirical Validation registry — the read-only mirror of architecture.md
 * §"EV-01 through EV-15".
 *
 * Single source of truth for the Algorithm Constants table rendered by the
 * Observability panel (TB-16). Resolves the *current effective value* of each
 * entry plus its provenance:
 *
 *   - `default`  → still the value declared in source.
 *   - `filtered` → a `apply_filters` hook is exposed and a third party changed
 *                  the value during this request.
 *   - `setting`  → the value is tied to a Settings field and is read from
 *                  SettingsRepository at render time.
 *
 * A grep-based sync test (EVRegistryTest::test_architecture_md_mentions_every_entry)
 * asserts that every EV-XX referenced in architecture.md is represented here.
 *
 * @package SemanticPosts\Observability
 */

declare( strict_types=1 );

namespace SemanticPosts\Observability;

use SemanticPosts\Crawler\Crawler;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Indexing\ColdStartProcessor;
use SemanticPosts\Indexing\RateLimiter;
use SemanticPosts\Ranking\DiverseMixMode;
use SemanticPosts\Ranking\FreshFirstMode;
use SemanticPosts\Settings\CostEstimator;
use SemanticPosts\Settings\SettingsRepository;
use SemanticPosts\Verification\VerificationPass;

final class EVRegistry {

	public const SOURCE_DEFAULT  = 'default';
	public const SOURCE_FILTERED = 'filtered';
	public const SOURCE_SETTING  = 'setting';

	/** @var SettingsRepository */
	private SettingsRepository $settings;

	/**
	 * @param SettingsRepository $settings Settings repo (used by setting-tied EVs).
	 */
	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	/**
	 * @return array<int,array{id:string, name:string, default:mixed, current:mixed, source:string, revisit:string}>
	 */
	public function entries(): array {
		$rows = array();
		foreach ( $this->definitions() as $def ) {
			$default = $def['default'];
			$current = $default;
			$source  = self::SOURCE_DEFAULT;

			if ( isset( $def['setting'] ) && '' !== $def['setting'] ) {
				$method = (string) $def['setting'];
				if ( method_exists( $this->settings, $method ) ) {
					$current = $this->settings->{$method}();
					$source  = self::SOURCE_SETTING;
				}
			} elseif ( isset( $def['filter'] ) && '' !== $def['filter'] ) {
				// Hooks listed in `filter` are always plugin-prefixed; sniff dynamic-name warning suppressed.
				$current = apply_filters( (string) $def['filter'], $default ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound
				if ( $current !== $default ) {
					$source = self::SOURCE_FILTERED;
				}
			}

			$rows[] = array(
				'id'      => (string) $def['id'],
				'name'    => (string) $def['name'],
				'default' => $default,
				'current' => $current,
				'source'  => $source,
				'revisit' => (string) $def['revisit'],
			);
		}
		return $rows;
	}

	/**
	 * Just the IDs in declaration order — used by the architecture-sync test.
	 *
	 * @return string[]
	 */
	public function ids(): array {
		return array_map( static fn( array $d ): string => (string) $d['id'], $this->definitions() );
	}

	/**
	 * Raw declarations — kept in one place to mirror architecture.md exactly.
	 * Heavier per-entry resolution lives in entries().
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function definitions(): array {
		return array(
			array(
				'id'      => 'EV-01',
				'name'    => 'Bootstrap phase cap (ADR-0008 N_b)',
				'default' => Crawler::PHASE_1_LIMIT,
				'revisit' => 'Phase-2 walks landing on posts not in Phase-1 top-K disproportionately.',
			),
			array(
				'id'      => 'EV-02',
				'name'    => 'Visit budget per insert (ADR-0008 B_v)',
				'default' => Crawler::VISIT_BUDGET,
				'revisit' => 'Verification MRD persistently > 1.0; walks exhausting budget without converging.',
			),
			array(
				'id'      => 'EV-03',
				'name'    => 'Random entry points (ADR-0008 L)',
				'default' => Crawler::ENTRY_POINTS,
				'revisit' => 'Verification MRD high on small clusters / topical isolates.',
			),
			array(
				'id'      => 'EV-04',
				'name'    => 'Warm crawler exploration sample (D2)',
				'default' => Crawler::RANDOM_SAMPLE,
				'revisit' => 'Broad-topic sites complain about narrow recommendations.',
			),
			array(
				'id'      => 'EV-05',
				'name'    => 'Verification pass MRD threshold (D1)',
				'default' => VerificationPass::THRESHOLD_DEFAULT,
				'filter'  => 'semantic_posts_verification_threshold',
				'revisit' => 'False-positive bursts or silence-while-complaints after 30d telemetry.',
			),
			array(
				'id'      => 'EV-06',
				'name'    => 'Verification pass sample size',
				'default' => VerificationPass::SAMPLE_SIZE,
				'revisit' => 'Week-to-week MRD too noisy (= too small) or runtime over budget (= too large).',
			),
			array(
				'id'      => 'EV-07',
				'name'    => 'Indexing batch size',
				'default' => ColdStartProcessor::POSTS_PER_BATCH,
				'revisit' => 'Cron ticks timing out before completion (= too large) or cold-start slower than NFR-IDX-1.',
			),
			array(
				'id'      => 'EV-08',
				'name'    => 'Inter-batch pause (seconds)',
				'default' => RateLimiter::MIN_GAP_SECONDS,
				'revisit' => '429 errors observed in steady state, or cold-start dominated by sleep.',
			),
			array(
				'id'      => 'EV-09',
				'name'    => 'Indexable text title repetition (ADR-0001)',
				'default' => IndexableTextBuilder::TITLE_REPEAT,
				'revisit' => 'Cross-category discovery rate persistently out of band, or eval shows title-dominated false matches.',
			),
			array(
				'id'      => 'EV-10',
				'name'    => 'Indexable text truncation cap (words)',
				'default' => IndexableTextBuilder::MAX_WORDS,
				'revisit' => 'Long-form sites complain conclusions do not influence recommendations.',
			),
			array(
				'id'      => 'EV-11',
				'name'    => 'Fresh-first decay (days)',
				'default' => FreshFirstMode::DEFAULT_DECAY_DAYS,
				'filter'  => 'semantic_posts_recency_decay',
				'revisit' => 'News sites: month-old posts above week-old; KB sites: decay buries evergreen content.',
			),
			array(
				'id'      => 'EV-12',
				'name'    => 'Diverse-mix MMR λ',
				'default' => DiverseMixMode::DEFAULT_LAMBDA,
				'filter'  => 'semantic_posts_mmr_lambda',
				'revisit' => 'Diverse-mix produces visibly off-topic items (λ too low) or near-identical items (λ too high).',
			),
			array(
				'id'      => 'EV-13',
				'name'    => 'Quality-bounded score threshold',
				'default' => 0.35,
				'setting' => 'score_threshold',
				'revisit' => 'Opt-in users see unrelated items (too low) or empty lists (too high).',
			),
			array(
				'id'      => 'EV-14',
				'name'    => 'Avg tokens / post (cost estimate)',
				'default' => CostEstimator::DEFAULT_AVG_TOKENS,
				'filter'  => 'semantic_posts_cost_avg_tokens_per_post',
				'revisit' => 'Real OpenAI billing consistently > 20% off the cost-preview number.',
			),
			array(
				'id'      => 'EV-15',
				'name'    => 'OpenAI pricing (USD / 1M tokens)',
				'default' => 'small $0.020 · large $0.130',
				'revisit' => 'OpenAI publishes new pricing — manual check during quarterly maintenance.',
			),
		);
	}
}
