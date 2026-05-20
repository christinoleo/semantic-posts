<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Security;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Security\ApiKeyStorage;

final class ApiKeyStorageTest extends TestCase {

	/** @var array<string,string> */
	private array $store = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		if ( ! defined( 'AUTH_SALT' ) ) {
			define( 'AUTH_SALT', 'unit-test-auth-salt-do-not-use-in-prod' );
		}

		$this->store = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return $this->store[ $key ] ?? $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->store[ $key ] = (string) $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->store[ $key ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_store_then_retrieve_roundtrips_the_key(): void {
		$storage = new ApiKeyStorage();
		$storage->store( 'sk-test-abc123' );

		$this->assertSame( 'sk-test-abc123', $storage->retrieve() );
	}

	public function test_empty_string_deletes_the_option(): void {
		$storage = new ApiKeyStorage();
		$storage->store( 'sk-initial' );
		$this->assertArrayHasKey( ApiKeyStorage::OPTION_NAME, $this->store );

		$storage->store( '' );
		$this->assertArrayNotHasKey( ApiKeyStorage::OPTION_NAME, $this->store );
		$this->assertNull( $storage->retrieve() );
	}

	public function test_retrieve_returns_null_when_no_key_stored(): void {
		$this->assertNull( ( new ApiKeyStorage() )->retrieve() );
	}

	public function test_two_writes_of_the_same_key_produce_different_ciphertexts(): void {
		$storage = new ApiKeyStorage();
		$storage->store( 'sk-same-value' );
		$first = $this->store[ ApiKeyStorage::OPTION_NAME ];

		$storage->store( 'sk-same-value' );
		$second = $this->store[ ApiKeyStorage::OPTION_NAME ];

		// Random IV per write ⇒ ciphertexts differ even for identical plaintext.
		$this->assertNotSame( $first, $second );
	}

	public function test_garbage_payload_is_cleared_and_returns_null(): void {
		// Simulate a corrupted option value (e.g. user pasted into DB by hand).
		$this->store[ ApiKeyStorage::OPTION_NAME ] = 'not-base64-or-too-short';

		$storage = new ApiKeyStorage();
		$this->assertNull( $storage->retrieve() );
		$this->assertArrayNotHasKey( ApiKeyStorage::OPTION_NAME, $this->store, 'Unreadable payload should be cleared.' );
	}

	public function test_auth_salt_change_makes_retrieve_return_null(): void {
		// We cannot redefine AUTH_SALT in PHP, but we can simulate the same effect:
		// stuff the option with a payload encrypted under a different key. Easiest:
		// tamper with the persisted ciphertext so OpenSSL fails to decrypt.
		$storage = new ApiKeyStorage();
		$storage->store( 'sk-original' );

		// Replace the stored payload with one encrypted under a DIFFERENT key —
		// exactly what `AUTH_SALT rotation` produces in practice. openssl_decrypt
		// returns false with the current key (padding mismatch), which the
		// retrieve() contract maps to null + warn.
		$wrong_key                                 = hash( 'sha256', 'different-auth-salt', true );
		$iv                                        = random_bytes( 16 );
		$wrong_cipher                              = openssl_encrypt( 'sk-original', 'aes-256-cbc', $wrong_key, OPENSSL_RAW_DATA, $iv );
		$this->store[ ApiKeyStorage::OPTION_NAME ] = base64_encode( $iv . $wrong_cipher );

		$this->assertNull( $storage->retrieve(), 'Tampered ciphertext must return null (key rotated equivalent).' );
	}
}
