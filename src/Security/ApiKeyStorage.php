<?php
/**
 * API-key encryption at rest.
 *
 * The OpenAI API key is the only secret the plugin persists. Stored as
 * AES-256-CBC ciphertext keyed off WordPress's per-site AUTH_SALT, so:
 *
 *   - A compromised database dump alone does not yield the key (attacker also
 *     needs wp-config.php, which lives outside the DB).
 *   - Rotating AUTH_SALT invalidates the stored key — `retrieve()` returns null
 *     and logs a warning so the user knows to re-enter it.
 *   - Random 16-byte IV per write means the same key encrypts to a different
 *     ciphertext each save, preventing trivial replay/identity inference.
 *
 * Encoding on disk: base64( iv (16 bytes) || ciphertext ).
 *
 * @package SemanticPosts\Security
 */

declare( strict_types=1 );

namespace SemanticPosts\Security;

use SemanticPosts\Logging;

class ApiKeyStorage {

	public const OPTION_NAME = 'semantic_posts_api_key';
	private const CIPHER     = 'aes-256-cbc';
	private const IV_LENGTH  = 16;
	private const KEY_LABEL  = 'sp_api_key';

	/**
	 * Encrypt and persist the given API key. Empty string deletes the option.
	 *
	 * @param string $api_key Plaintext API key.
	 */
	public function store( string $api_key ): void {
		if ( '' === $api_key ) {
			delete_option( self::OPTION_NAME );
			return;
		}

		$iv         = $this->random_iv();
		$ciphertext = openssl_encrypt( $api_key, self::CIPHER, $this->derive_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			Logging::error( 'ApiKeyStorage::store openssl_encrypt failed.' );
			return;
		}

		$payload = base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		update_option( self::OPTION_NAME, $payload, false );
	}

	/**
	 * Retrieve and decrypt the stored API key.
	 *
	 * @return string|null Decrypted key, or null if missing / undecryptable
	 *                     (e.g. AUTH_SALT rotated since storage). A null result
	 *                     warns via Logging so the user is notified.
	 */
	public function retrieve(): ?string {
		$stored = get_option( self::OPTION_NAME, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}

		$blob = base64_decode( $stored, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $blob || strlen( $blob ) <= self::IV_LENGTH ) {
			Logging::warn( 'ApiKeyStorage::retrieve found unreadable payload — clearing.' );
			delete_option( self::OPTION_NAME );
			return null;
		}

		$iv         = substr( $blob, 0, self::IV_LENGTH );
		$ciphertext = substr( $blob, self::IV_LENGTH );

		$plaintext = openssl_decrypt( $ciphertext, self::CIPHER, $this->derive_key(), OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			Logging::warn( 'ApiKeyStorage::retrieve decrypt failed — AUTH_SALT likely rotated. User must re-enter key.' );
			return null;
		}

		return $plaintext;
	}

	/**
	 * Derive a 32-byte symmetric key from AUTH_SALT via HMAC-SHA256. Returns
	 * a raw binary string suitable for AES-256-CBC.
	 */
	private function derive_key(): string {
		$salt = defined( 'AUTH_SALT' ) ? (string) AUTH_SALT : '';
		return hash_hmac( 'sha256', self::KEY_LABEL, $salt, true );
	}

	/**
	 * Generate a cryptographically random 16-byte IV (CBC mode requires
	 * unpredictable IV per encryption).
	 */
	private function random_iv(): string {
		return random_bytes( self::IV_LENGTH );
	}
}
