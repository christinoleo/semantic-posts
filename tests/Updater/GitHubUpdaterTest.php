<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Updater;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Updater\GitHubUpdater;

final class GitHubUpdaterTest extends TestCase {

	/** @var array<string,mixed> */
	private array $transient_store = array();

	/** @var array<int,array{0:string,1:array<string,mixed>}> */
	private array $http_calls = array();

	/** @var array<string,mixed> */
	private array $next_response = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->transient_store = array();
		$this->http_calls      = array();
		$this->next_response   = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{}',
		);

		Functions\when( 'get_transient' )->alias(
			fn( $k ) => array_key_exists( $k, $this->transient_store ) ? $this->transient_store[ $k ] : false
		);
		Functions\when( 'set_transient' )->alias(
			function ( $k, $v ) {
				$this->transient_store[ $k ] = $v;
				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function ( $url, $args = array() ) {
				$this->http_calls[] = array( $url, $args );
				return $this->next_response;
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			fn( $r ) => is_array( $r ) ? (int) ( $r['response']['code'] ?? 0 ) : 0
		);
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			fn( $r ) => is_array( $r ) ? (string) ( $r['body'] ?? '' ) : ''
		);
		Functions\when( 'plugin_basename' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function updater( string $current = '0.1.0' ): GitHubUpdater {
		return new GitHubUpdater(
			'semantic-posts/semantic-posts.php',
			$current,
			'christinoleo',
			'semantic-posts'
		);
	}

	private function release_body( string $tag = 'v0.2.0' ): string {
		return wp_json_encode_compat(
			array(
				'tag_name'     => $tag,
				'published_at' => '2026-06-01T12:00:00Z',
				'body'         => 'Release notes here.',
				'assets'       => array(
					array( 'name' => 'source.tar.gz', 'browser_download_url' => 'https://example.test/x.tar.gz' ),
					array( 'name' => 'semantic-posts-' . ltrim( $tag, 'v' ) . '.zip', 'browser_download_url' => 'https://example.test/x.zip' ),
				),
			)
		);
	}

	public function test_fetch_returns_null_on_http_error(): void {
		$this->next_response = array(
			'response' => array( 'code' => 500 ),
			'body'     => '',
		);
		$out = $this->updater()->fetch_latest_release();
		$this->assertNull( $out );
		// Failure cached as empty array.
		$this->assertSame( array(), $this->transient_store[ GitHubUpdater::TRANSIENT_KEY ] );
	}

	public function test_fetch_returns_null_on_malformed_body(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => '{"not_a_release":true}',
		);
		$out = $this->updater()->fetch_latest_release();
		$this->assertNull( $out );
	}

	public function test_fetch_returns_null_when_no_zip_asset(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode_compat(
				array(
					'tag_name' => 'v0.2.0',
					'assets'   => array(
						array( 'name' => 'source.tar.gz', 'browser_download_url' => 'https://example.test/x.tar.gz' ),
					),
				)
			),
		);
		$out = $this->updater()->fetch_latest_release();
		$this->assertNull( $out );
	}

	public function test_fetch_extracts_zip_asset_and_strips_v_prefix(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $this->release_body( 'v0.2.0' ),
		);
		$out = $this->updater()->fetch_latest_release();
		$this->assertSame( '0.2.0', $out['version'] );
		$this->assertSame( 'https://example.test/x.zip', $out['zip_url'] );
	}

	public function test_fetch_uses_cache_on_repeat_call(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $this->release_body( 'v0.2.0' ),
		);
		$this->updater()->fetch_latest_release();
		$this->updater()->fetch_latest_release();
		$this->updater()->fetch_latest_release();
		$this->assertCount( 1, $this->http_calls, 'Subsequent fetches should hit the cache.' );
	}

	public function test_check_for_update_skips_when_remote_is_not_newer(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $this->release_body( 'v0.1.0' ),
		);
		$transient = (object) array( 'response' => array(), 'checked' => array( 'semantic-posts/semantic-posts.php' => '0.1.0' ) );
		$out       = $this->updater( '0.1.0' )->check_for_update( $transient );
		$this->assertSame( array(), $out->response );
	}

	public function test_check_for_update_injects_payload_when_newer_remote(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $this->release_body( 'v0.2.0' ),
		);
		$transient = (object) array( 'response' => array(), 'checked' => array() );
		$out       = $this->updater( '0.1.0' )->check_for_update( $transient );
		$this->assertArrayHasKey( 'semantic-posts/semantic-posts.php', $out->response );
		$payload = $out->response['semantic-posts/semantic-posts.php'];
		$this->assertSame( '0.2.0', $payload->new_version );
		$this->assertSame( 'https://example.test/x.zip', $payload->package );
		$this->assertSame( 'semantic-posts', $payload->slug );
	}

	public function test_plugins_api_returns_payload_for_our_slug(): void {
		$this->next_response = array(
			'response' => array( 'code' => 200 ),
			'body'     => $this->release_body( 'v0.2.0' ),
		);
		$args = (object) array( 'slug' => 'semantic-posts' );
		$out  = $this->updater()->plugins_api( false, 'plugin_information', $args );
		$this->assertIsObject( $out );
		$this->assertSame( '0.2.0', $out->version );
		$this->assertSame( 'https://example.test/x.zip', $out->download_link );
	}

	public function test_plugins_api_ignores_other_slugs(): void {
		$args = (object) array( 'slug' => 'akismet' );
		$out  = $this->updater()->plugins_api( false, 'plugin_information', $args );
		$this->assertFalse( $out );
		$this->assertCount( 0, $this->http_calls );
	}

	public function test_plugins_api_ignores_other_actions(): void {
		$args = (object) array( 'slug' => 'semantic-posts' );
		$out  = $this->updater()->plugins_api( false, 'query_plugins', $args );
		$this->assertFalse( $out );
	}
}

/** Local helper because Brain\Monkey doesn't stub wp_json_encode by default. */
function wp_json_encode_compat( $data ): string {
	return (string) json_encode( $data, JSON_UNESCAPED_SLASHES );
}
