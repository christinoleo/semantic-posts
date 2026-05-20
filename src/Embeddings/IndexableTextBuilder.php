<?php
/**
 * Compose the per-post text string sent to the embedding provider, per ADR-0001.
 *
 * Composition:
 *   {title}\n\n{title}\n\n{title}\n\n{manual_excerpt}\n\n{cleaned_content}
 *
 * Manual excerpts only — auto-generated excerpts add no signal.
 * Shortcodes are kept as raw `[name]` tokens (NOT rendered) to avoid HTTP
 * side-effects and unbounded rendering time during indexing.
 * HTML is stripped via wp_strip_all_tags.
 * Truncated to MAX_WORDS words to stay under the 8191-token limit of
 * text-embedding-3-small (heuristic 1 token ≈ 0.75 words).
 *
 * @package SemanticPosts\Embeddings
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings;

use SemanticPosts\Logging;
use WP_Post;

class IndexableTextBuilder {

	public const MAX_WORDS       = 6500;
	public const TITLE_REPEAT    = 3;
	private const PARA_SEPARATOR = "\n\n";

	/**
	 * @param  WP_Post $post Source post.
	 * @return string Indexable text ready to ship to a Provider.
	 */
	public function build( WP_Post $post ): string {
		$title           = $this->normalize( (string) $post->post_title );
		$manual_excerpt  = $this->manual_excerpt( $post );
		$cleaned_content = $this->normalize( wp_strip_all_tags( (string) $post->post_content ) );

		$segments = array_fill( 0, self::TITLE_REPEAT, $title );
		if ( '' !== $manual_excerpt ) {
			$segments[] = $manual_excerpt;
		}
		if ( '' !== $cleaned_content ) {
			$segments[] = $cleaned_content;
		}

		$composed = implode( self::PARA_SEPARATOR, $segments );

		return $this->truncate_words( $composed, $post->ID );
	}

	/**
	 * Return post_excerpt only when it is author-curated. WP's `the_excerpt`
	 * auto-generates from content when post_excerpt is empty — those auto-versions
	 * are redundant with content (which we include separately) and we skip them.
	 *
	 * @param WP_Post $post Source post.
	 */
	private function manual_excerpt( WP_Post $post ): string {
		$raw = (string) $post->post_excerpt;
		if ( '' === trim( $raw ) ) {
			return '';
		}
		return $this->normalize( wp_strip_all_tags( $raw ) );
	}

	/**
	 * Collapse runs of whitespace and trim. Keeps the encoded payload size
	 * predictable across posts whose authors used different line-break styles.
	 *
	 * @param string $text Raw text segment.
	 */
	private function normalize( string $text ): string {
		$cleaned = preg_replace( '/\s+/u', ' ', $text );
		return trim( (string) $cleaned );
	}

	/**
	 * Truncate the composed text to MAX_WORDS words. Logs a single info-level
	 * line when truncation actually happens so retros can spot long-content drops.
	 *
	 * @param string $text    Composed indexable text.
	 * @param int    $post_id Source post ID (for log context).
	 */
	private function truncate_words( string $text, int $post_id ): string {
		$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		if ( ! is_array( $words ) ) {
			return $text;
		}

		if ( count( $words ) <= self::MAX_WORDS ) {
			return $text;
		}

		$truncated = implode( ' ', array_slice( $words, 0, self::MAX_WORDS ) );
		Logging::info(
			'IndexableTextBuilder truncated content to MAX_WORDS.',
			array(
				'post_id'         => $post_id,
				'original_words'  => count( $words ),
				'truncated_words' => self::MAX_WORDS,
			)
		);
		return $truncated;
	}
}
