<?php
/**
 * Single source of truth for the plugin's persisted settings.
 *
 * Storage: one autoloaded option `semantic_posts_settings` (NFR-HOST-5 caps
 * total payload at <1KB). All reads/writes go through this class so the
 * default-merge and sanitize contract is consistent.
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

use SemanticPosts\Ranking\Mode;

/**
 * Settings persistence + sanitization.
 */
class SettingsRepository {

	public const OPTION_NAME = 'semantic_posts_settings';

	public const MODE_AUTO_INJECT = 'auto-inject';
	public const MODE_SHORTCODE   = 'shortcode';
	public const MODE_OFF         = 'off';

	public const FREQUENCY_HOURLY    = 'hourly';
	public const FREQUENCY_SIX_HOURS = 'six_hours';
	public const FREQUENCY_DAILY     = 'daily';

	public const MODEL_SMALL = 'openai/text-embedding-3-small';
	public const MODEL_LARGE = 'openai/text-embedding-3-large';

	private const DEFAULTS = array(
		'post_types'      => array( 'post' ),
		'display_mode'    => self::MODE_AUTO_INJECT,
		'ranking_mode'    => Mode::MOST_RELEVANT,
		'embedding_model' => self::MODEL_SMALL,
		'related_count'   => 5,
		'quality_bounded' => false,
		'min_items'       => 3,
		'score_threshold' => 0.35,
		'cron_frequency'  => self::FREQUENCY_HOURLY,
	);

	private const VALID_MODES = array(
		self::MODE_AUTO_INJECT,
		self::MODE_SHORTCODE,
		self::MODE_OFF,
	);

	private const VALID_RANKING_MODES = array(
		Mode::MOST_RELEVANT,
		Mode::FRESH_FIRST,
		Mode::DIVERSE_MIX,
	);

	private const VALID_MODELS = array(
		self::MODEL_SMALL,
		self::MODEL_LARGE,
	);

	private const VALID_FREQUENCIES = array(
		self::FREQUENCY_HOURLY,
		self::FREQUENCY_SIX_HOURS,
		self::FREQUENCY_DAILY,
	);

	/**
	 * @return array<string,mixed>
	 */
	public function all(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return $this->merge_with_defaults( $stored );
	}

	/**
	 * @return string[]
	 */
	public function post_types(): array {
		return $this->all()['post_types'];
	}

	/** Current display mode (MODE_AUTO_INJECT / MODE_SHORTCODE / MODE_OFF). */
	public function display_mode(): string {
		return $this->all()['display_mode'];
	}

	/** Active ranking-mode slug (one of Mode::*). */
	public function ranking_mode(): string {
		return $this->all()['ranking_mode'];
	}

	/** Configured embedding-model slug (MODEL_SMALL or MODEL_LARGE). */
	public function embedding_model(): string {
		return $this->all()['embedding_model'];
	}

	/** Related-post count to render (3..10). */
	public function related_count(): int {
		return $this->all()['related_count'];
	}

	/** Whether quality-bounded mode (hide widget on low-confidence matches) is on. */
	public function quality_bounded(): bool {
		return $this->all()['quality_bounded'];
	}

	/** Minimum number of items required for the widget to render in quality-bounded mode. */
	public function min_items(): int {
		return $this->all()['min_items'];
	}

	/** Score threshold for an item to count toward quality-bounded mode. */
	public function score_threshold(): float {
		return $this->all()['score_threshold'];
	}

	/** Cron frequency slug (hourly / six_hours / daily). */
	public function cron_frequency(): string {
		return $this->all()['cron_frequency'];
	}

	/**
	 * Persist a settings array after sanitization. Returns the sanitized array
	 * that was written.
	 *
	 * @param array<string,mixed> $raw Raw form data.
	 * @return array<string,mixed>
	 */
	public function save( array $raw ): array {
		$sanitized = $this->sanitize( $raw );
		update_option( self::OPTION_NAME, $sanitized, true );
		return $sanitized;
	}

	/**
	 * @param array<string,mixed> $raw Raw input.
	 * @return array<string,mixed>
	 */
	public function sanitize( array $raw ): array {
		$post_types = array();
		if ( isset( $raw['post_types'] ) && is_array( $raw['post_types'] ) ) {
			foreach ( $raw['post_types'] as $pt ) {
				if ( ! is_string( $pt ) ) {
					continue;
				}
				$clean = sanitize_key( $pt );
				if ( '' === $clean ) {
					continue;
				}
				$post_types[] = $clean;
			}
			$post_types = array_values( array_unique( $post_types ) );
		}
		if ( empty( $post_types ) ) {
			$post_types = self::DEFAULTS['post_types'];
		}

		$mode = isset( $raw['display_mode'] ) && is_string( $raw['display_mode'] )
			? sanitize_key( $raw['display_mode'] )
			: '';
		if ( ! in_array( $mode, self::VALID_MODES, true ) ) {
			$mode = self::DEFAULTS['display_mode'];
		}

		$ranking = isset( $raw['ranking_mode'] ) && is_string( $raw['ranking_mode'] )
			? sanitize_text_field( $raw['ranking_mode'] )
			: '';
		if ( ! in_array( $ranking, self::VALID_RANKING_MODES, true ) ) {
			$ranking = self::DEFAULTS['ranking_mode'];
		}

		$model = isset( $raw['embedding_model'] ) && is_string( $raw['embedding_model'] )
			? sanitize_text_field( $raw['embedding_model'] )
			: '';
		if ( ! in_array( $model, self::VALID_MODELS, true ) ) {
			$model = self::DEFAULTS['embedding_model'];
		}

		$count = isset( $raw['related_count'] ) ? (int) $raw['related_count'] : self::DEFAULTS['related_count'];
		$count = max( 3, min( 10, $count ) );

		$quality_bounded = isset( $raw['quality_bounded'] )
			&& '' !== $raw['quality_bounded']
			&& '0' !== $raw['quality_bounded']
			&& false !== $raw['quality_bounded'];

		$min_items = isset( $raw['min_items'] ) ? (int) $raw['min_items'] : self::DEFAULTS['min_items'];
		$min_items = max( 1, min( 10, $min_items ) );

		$threshold = isset( $raw['score_threshold'] ) ? (float) $raw['score_threshold'] : self::DEFAULTS['score_threshold'];
		$threshold = max( 0.0, min( 1.0, $threshold ) );

		$frequency = isset( $raw['cron_frequency'] ) && is_string( $raw['cron_frequency'] )
			? sanitize_key( $raw['cron_frequency'] )
			: '';
		if ( ! in_array( $frequency, self::VALID_FREQUENCIES, true ) ) {
			$frequency = self::DEFAULTS['cron_frequency'];
		}

		return array(
			'post_types'      => $post_types,
			'display_mode'    => $mode,
			'ranking_mode'    => $ranking,
			'embedding_model' => $model,
			'related_count'   => $count,
			'quality_bounded' => $quality_bounded,
			'min_items'       => $min_items,
			'score_threshold' => $threshold,
			'cron_frequency'  => $frequency,
		);
	}

	/**
	 * Whether the given post type is configured to receive widgets.
	 *
	 * @param string $post_type Post type slug to check.
	 */
	public function covers_post_type( string $post_type ): bool {
		return in_array( $post_type, $this->post_types(), true );
	}

	/**
	 * @param array<string,mixed> $stored Stored option value.
	 * @return array<string,mixed>
	 */
	private function merge_with_defaults( array $stored ): array {
		$source = array();
		foreach ( self::DEFAULTS as $key => $default ) {
			$source[ $key ] = array_key_exists( $key, $stored ) ? $stored[ $key ] : $default;
		}
		return $this->sanitize( $source );
	}
}
