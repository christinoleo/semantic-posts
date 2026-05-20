<?php
/**
 * One-shot OpenAI API-key validator.
 *
 * Used by the admin Settings → API key save flow (TB-12) to verify a candidate
 * key before persisting it. Issues a minimal embeddings call (one whitespace
 * token) and inspects the response status to decide valid/invalid.
 *
 * Distinct from OpenAIProvider so we can validate WITHOUT mutating storage —
 * the key under test never touches ApiKeyStorage if validation fails.
 *
 * @package SemanticPosts\Security
 */

declare( strict_types=1 );

namespace SemanticPosts\Security;

class ApiKeyValidator {

	private const ENDPOINT = 'https://api.openai.com/v1/embeddings';
	private const MODEL    = 'text-embedding-3-small';
	private const TIMEOUT  = 10;

	/**
	 * Return shape used by AjaxHandler. `error_code` is one of:
	 *   ok              → valid
	 *   unauthorized    → key rejected (401/403)
	 *   network         → HTTP layer failed
	 *   rate_limited    → 429 (still likely valid; treat as ok for storage)
	 *   server_error    → 5xx
	 *   malformed       → unexpected response shape
	 *   empty           → no key supplied
	 *
	 * @param  string $api_key Candidate key to validate.
	 * @return array{ok:bool, error_code:string, message:string}
	 */
	public function validate( string $api_key ): array {
		$api_key = trim( $api_key );
		if ( '' === $api_key ) {
			return array(
				'ok'         => false,
				'error_code' => 'empty',
				'message'    => 'API key is empty.',
			);
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'model' => self::MODEL,
						'input' => 'ping',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'         => false,
				'error_code' => 'network',
				'message'    => (string) $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );

		if ( 401 === $status || 403 === $status ) {
			return array(
				'ok'         => false,
				'error_code' => 'unauthorized',
				'message'    => 'OpenAI rejected the API key.',
			);
		}
		if ( 429 === $status ) {
			// Rate limited — key is valid; just throttled.
			return array(
				'ok'         => true,
				'error_code' => 'rate_limited',
				'message'    => 'Key validated (under rate limit).',
			);
		}
		if ( $status >= 500 ) {
			return array(
				'ok'         => false,
				'error_code' => 'server_error',
				'message'    => 'OpenAI returned ' . $status . '. Try again shortly.',
			);
		}
		if ( $status >= 400 ) {
			return array(
				'ok'         => false,
				'error_code' => 'unauthorized',
				'message'    => 'OpenAI rejected the request (HTTP ' . $status . ').',
			);
		}

		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['data'][0]['embedding'] ) ) {
			return array(
				'ok'         => false,
				'error_code' => 'malformed',
				'message'    => 'Unexpected response from OpenAI.',
			);
		}

		return array(
			'ok'         => true,
			'error_code' => 'ok',
			'message'    => 'API key validated.',
		);
	}
}
