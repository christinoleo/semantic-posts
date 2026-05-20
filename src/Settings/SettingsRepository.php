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

	/**
	 * @var array{post_types: string[], display_mode: string, ranking_mode: string}
	 */
	private const DEFAULTS = array(
		'post_types'   => array( 'post' ),
		'display_mode' => self::MODE_AUTO_INJECT,
		'ranking_mode' => Mode::MOST_RELEVANT,
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

	/**
	 * @return array{post_types: string[], display_mode: string, ranking_mode: string}
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

	/**
	 * @return string Current display mode (MODE_AUTO_INJECT / MODE_SHORTCODE / MODE_OFF).
	 */
	public function display_mode(): string {
		return $this->all()['display_mode'];
	}

	/**
	 * @return string Active ranking-mode slug (one of Mode::*).
	 */
	public function ranking_mode(): string {
		return $this->all()['ranking_mode'];
	}

	/**
	 * Persist a settings array after sanitization. Returns the sanitized array
	 * that was written, so callers (and the Settings page) can display it.
	 *
	 * @param array<string,mixed> $raw Raw form data.
	 * @return array{post_types: string[], display_mode: string, ranking_mode: string}
	 */
	public function save( array $raw ): array {
		$sanitized = $this->sanitize( $raw );
		update_option( self::OPTION_NAME, $sanitized, true );
		return $sanitized;
	}

	/**
	 * @param array<string,mixed> $raw Raw input.
	 * @return array{post_types: string[], display_mode: string, ranking_mode: string}
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

		return array(
			'post_types'   => $post_types,
			'display_mode' => $mode,
			'ranking_mode' => $ranking,
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
	 * @return array{post_types: string[], display_mode: string, ranking_mode: string}
	 */
	private function merge_with_defaults( array $stored ): array {
		return $this->sanitize(
			array(
				'post_types'   => $stored['post_types'] ?? self::DEFAULTS['post_types'],
				'display_mode' => $stored['display_mode'] ?? self::DEFAULTS['display_mode'],
				'ranking_mode' => $stored['ranking_mode'] ?? self::DEFAULTS['ranking_mode'],
			)
		);
	}
}
