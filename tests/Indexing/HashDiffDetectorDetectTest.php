<?php
/**
 * Coverage for HashDiffDetector::detect (TB-07 extension).
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\IndexableTextBuilder;
use SemanticPosts\Indexing\HashDiffDetector;

final class HashDiffDetectorDetectTest extends TestCase {

	/** @var array<int,array<string,mixed>> */
	private array $meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->meta = array();

		if ( ! class_exists( \WP_Post::class ) ) {
			eval( 'class WP_Post { public int $ID = 0; public string $post_title = ""; public string $post_excerpt = ""; public string $post_content = ""; public string $post_type = "post"; }' );
		}

		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $h ) => trim( strip_tags( (string) $h ) ) );
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key /* , $single */ ) {
				return $this->meta[ (int) $post_id ][ (string) $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				$this->meta[ (int) $post_id ][ (string) $key ] = $value;
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function post( int $id, string $title, string $content ): \WP_Post {
		$p               = new \WP_Post();
		$p->ID           = $id;
		$p->post_title   = $title;
		$p->post_content = $content;
		return $p;
	}

	public function test_first_detect_returns_true_and_sets_hash_plus_dirty(): void {
		$d   = new HashDiffDetector( new IndexableTextBuilder() );
		$p   = $this->post( 1, 'Hello', 'Body.' );
		$res = $d->detect( $p );

		$this->assertTrue( $res );
		$this->assertArrayHasKey( '_sp_text_hash', $this->meta[1] );
		$this->assertSame( 1, $this->meta[1]['_sp_dirty'] );
	}

	public function test_unchanged_content_returns_false_no_write(): void {
		$d = new HashDiffDetector( new IndexableTextBuilder() );
		$p = $this->post( 1, 'Hello', 'Body.' );

		$d->detect( $p ); // seeds hash
		// Pre-condition: dirty cleared as if EmbedJob ran.
		unset( $this->meta[1]['_sp_dirty'] );

		$res = $d->detect( $p );
		$this->assertFalse( $res );
		$this->assertArrayNotHasKey( '_sp_dirty', $this->meta[1] );
	}

	public function test_changed_content_marks_dirty_and_updates_hash(): void {
		$d   = new HashDiffDetector( new IndexableTextBuilder() );
		$p1  = $this->post( 1, 'Hello', 'Body.' );
		$d->detect( $p1 );
		$first_hash = $this->meta[1]['_sp_text_hash'];
		unset( $this->meta[1]['_sp_dirty'] );

		$p2 = $this->post( 1, 'Hello', 'New body.' );
		$res = $d->detect( $p2 );

		$this->assertTrue( $res );
		$this->assertNotSame( $first_hash, $this->meta[1]['_sp_text_hash'] );
		$this->assertSame( 1, $this->meta[1]['_sp_dirty'] );
	}

	public function test_purge_clears_both_keys(): void {
		$this->meta[1]['_sp_text_hash'] = 'abc';
		$this->meta[1]['_sp_dirty']     = 1;

		$d = new HashDiffDetector();
		// purge uses delete_post_meta — stub it.
		Functions\when( 'delete_post_meta' )->alias(
			function ( $post_id, $key ) {
				unset( $this->meta[ (int) $post_id ][ (string) $key ] );
				return true;
			}
		);
		$d->purge( 1 );

		$this->assertArrayNotHasKey( '_sp_text_hash', $this->meta[1] );
		$this->assertArrayNotHasKey( '_sp_dirty', $this->meta[1] );
	}
}
