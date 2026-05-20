<?php
/**
 * OpenAI embeddings adapter — text-embedding-3-small (1536 dims).
 *
 * Single responsibility: convert one text string into one float[] via the
 * OpenAI HTTP API. Caller (TickProcessor / EmbedJob in TB-06) owns retry,
 * scheduling, and persistence.
 *
 * @package SemanticPosts\Embeddings
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings;

use SemanticPosts\Embeddings\Exception\FatalException;
use SemanticPosts\Embeddings\Exception\RetryableException;
use SemanticPosts\Logging;
use SemanticPosts\Security\ApiKeyStorage;

class OpenAIProvider implements Provider {

	public const NAME          = 'openai';
	public const MODEL         = 'text-embedding-3-small';
	public const ENDPOINT      = 'https://api.openai.com/v1/embeddings';
	public const MAX_TOKENS    = 8191;
	public const COST_PER_MTOK = 0.02; // $0.02 / 1M tokens (text-embedding-3-small, as of 2026).
	public const EXPECTED_DIM  = 1536;
	private const TIMEOUT_SECS = 30;

	/**
	 * API-key storage; consulted on every embed call so key rotations take
	 * effect without restart.
	 *
	 * @var ApiKeyStorage
	 */
	private ApiKeyStorage $key_storage;

	/**
	 * @param ApiKeyStorage $key_storage Storage adapter for the OpenAI API key.
	 */
	public function __construct( ApiKeyStorage $key_storage ) {
		$this->key_storage = $key_storage;
	}

	/**
	 * @return string Stable provider name used in logs / observability.
	 */
	public function name(): string {
		return self::NAME;
	}

	/**
	 * @return int Max input tokens accepted by text-embedding-3-small.
	 */
	public function maxInputTokens(): int {
		return self::MAX_TOKENS;
	}

	/**
	 * @return float USD per million tokens at current OpenAI pricing.
	 */
	public function costPerMillionTokens(): float {
		return self::COST_PER_MTOK;
	}

	/**
	 * @param  string $text Already-truncated indexable text.
	 * @return float[]
	 * @throws FatalException     Missing key, 4xx (non-429), malformed response.
	 * @throws RetryableException 5xx, 429, network timeout.
	 */
	public function embed( string $text ): array {
		$api_key = $this->key_storage->retrieve();
		if ( null === $api_key || '' === $api_key ) {
			throw new FatalException( 'OpenAIProvider: API key not configured.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT_SECS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => self::MODEL,
						'input' => $text,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			// Network timeouts / DNS failures / SSL errors all surface here.
			$code    = $response->get_error_code();
			$message = $response->get_error_message();
			Logging::warn(
				'OpenAIProvider WP_Error',
				array(
					'code' => $code,
					'msg'  => $message,
				)
			);
			throw new RetryableException( 'OpenAIProvider network failure: ' . $message );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( $status >= 500 || 429 === $status ) {
			throw new RetryableException( sprintf( 'OpenAIProvider HTTP %d — retryable.', $status ) );
		}

		if ( $status >= 400 ) {
			// 4xx other than 429: bad request, unauthorized, quota exceeded, etc.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are not rendered as HTML; they go to logs.
			throw new FatalException( sprintf( 'OpenAIProvider HTTP %d — fatal: %s', $status, $body ) );
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'][0]['embedding'] ) || ! is_array( $decoded['data'][0]['embedding'] ) ) {
			throw new FatalException( 'OpenAIProvider malformed response — missing data[0].embedding.' );
		}

		$embedding = $decoded['data'][0]['embedding'];
		if ( self::EXPECTED_DIM !== count( $embedding ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages go to logs, not HTML.
			throw new FatalException(
				sprintf( 'OpenAIProvider unexpected embedding dimension %d (expected %d).', count( $embedding ), self::EXPECTED_DIM )
			);
		}

		// Defensive: ensure all values are float.
		return array_map( 'floatval', $embedding );
	}
}
