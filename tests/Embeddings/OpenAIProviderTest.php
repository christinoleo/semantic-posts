<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Embeddings;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\Exception\FatalException;
use SemanticPosts\Embeddings\Exception\RetryableException;
use SemanticPosts\Embeddings\OpenAIProvider;
use SemanticPosts\Security\ApiKeyStorage;

/** Hand-rolled storage stub that always returns the configured key. */
final class StubApiKey extends ApiKeyStorage {
	public ?string $key = 'sk-stub';

	public function retrieve(): ?string {
		return $this->key;
	}
}

final class OpenAIProviderTest extends TestCase {

	private function bootstrap_wp_http(): void {
		// WP_Error stand-in if WP isn't loaded.
		if ( ! class_exists( \WP_Error::class ) ) {
			eval( '
				class WP_Error {
					private string $code;
					private string $message;
					public function __construct( $code, $message ) { $this->code = (string) $code; $this->message = (string) $message; }
					public function get_error_code() { return $this->code; }
					public function get_error_message() { return $this->message; }
				}
			' );
		}

		Functions\when( 'is_wp_error' )->alias(
			static fn( $thing ) => $thing instanceof \WP_Error
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value ) => json_encode( $value )
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			static fn( $resp ) => is_array( $resp ) ? ( $resp['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			static fn( $resp ) => is_array( $resp ) ? ( $resp['body'] ?? '' ) : ''
		);
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->bootstrap_wp_http();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a 1536-element embedding so the provider's dim check passes.
	 *
	 * @return float[]
	 */
	private function valid_embedding(): array {
		return array_fill( 0, 1536, 0.5 );
	}

	public function test_success_returns_float_array_of_expected_dimension(): void {
		$embedding = $this->valid_embedding();
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'data' => array( array( 'embedding' => $embedding ) ) ) ),
			)
		);

		$provider = new OpenAIProvider( new StubApiKey() );
		$result   = $provider->embed( 'hello world' );

		$this->assertCount( 1536, $result );
		$this->assertContainsOnly( 'float', $result );
	}

	public function test_missing_api_key_throws_fatal(): void {
		$storage      = new StubApiKey();
		$storage->key = null;

		$this->expectException( FatalException::class );
		$this->expectExceptionMessage( 'API key not configured' );

		( new OpenAIProvider( $storage ) )->embed( 'whatever' );
	}

	public function test_500_response_throws_retryable(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 500 ),
				'body'     => '{"error":"server overloaded"}',
			)
		);

		$this->expectException( RetryableException::class );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_429_response_throws_retryable(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 429 ),
				'body'     => '{"error":"rate limited"}',
			)
		);

		$this->expectException( RetryableException::class );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_400_response_throws_fatal(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 400 ),
				'body'     => '{"error":"bad request"}',
			)
		);

		$this->expectException( FatalException::class );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_401_response_throws_fatal(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 401 ),
				'body'     => '{"error":"invalid api key"}',
			)
		);

		$this->expectException( FatalException::class );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_network_timeout_wp_error_throws_retryable(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' )
		);

		$this->expectException( RetryableException::class );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_malformed_response_body_throws_fatal(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => 'totally not json',
			)
		);

		$this->expectException( FatalException::class );
		$this->expectExceptionMessage( 'malformed response' );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_wrong_dimension_throws_fatal(): void {
		Functions\when( 'wp_remote_post' )->justReturn(
			array(
				'response' => array( 'code' => 200 ),
				'body'     => json_encode( array( 'data' => array( array( 'embedding' => array_fill( 0, 1024, 0.5 ) ) ) ) ),
			)
		);

		$this->expectException( FatalException::class );
		$this->expectExceptionMessage( 'unexpected embedding dimension' );
		( new OpenAIProvider( new StubApiKey() ) )->embed( 'x' );
	}

	public function test_name_and_metadata_are_stable_for_observability_panel(): void {
		$provider = new OpenAIProvider( new StubApiKey() );
		$this->assertSame( 'openai', $provider->name() );
		$this->assertSame( 8191, $provider->maxInputTokens() );
		$this->assertGreaterThan( 0.0, $provider->costPerMillionTokens() );
	}
}
