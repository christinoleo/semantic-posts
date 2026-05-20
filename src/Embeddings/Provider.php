<?php
/**
 * Contract every embedding provider must implement. Future Pro-tier providers
 * (e.g. Cohere, Voyage, local models) drop in by satisfying this interface;
 * the rest of the indexing pipeline does not change.
 *
 * @package SemanticPosts\Embeddings
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings;

use SemanticPosts\Embeddings\Exception\FatalException;
use SemanticPosts\Embeddings\Exception\RetryableException;

interface Provider {

	/**
	 * Short stable identifier used in logs and the observability panel.
	 */
	public function name(): string;

	/**
	 * Embed a single text string and return its vector.
	 *
	 * @param  string $text Indexable text — caller has already composed and
	 *                      truncated per ADR-0001.
	 * @return float[]
	 * @throws RetryableException On 5xx / 429 / network timeout.
	 * @throws FatalException     On 4xx (non-429), malformed response, or
	 *                            missing/invalid API key.
	 */
	public function embed( string $text ): array;

	/**
	 * Maximum number of input tokens the provider accepts in a single embed call.
	 * Callers use this for client-side budget checks before truncation.
	 */
	public function maxInputTokens(): int;

	/**
	 * Cost per million tokens (in USD) for the provider's current pricing.
	 * Used by the cost-preview UI in the bulk-index admin flow.
	 */
	public function costPerMillionTokens(): float;
}
