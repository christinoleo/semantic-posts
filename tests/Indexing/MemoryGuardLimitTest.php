<?php
/**
 * Coverage for the v0.1.3 fix: MemoryGuard reads the *runtime*
 * `ini_get('memory_limit')` first (which reflects WP's `wp_raise_memory_limit`
 * call to WP_MAX_MEMORY_LIMIT in admin/cron context). The previous behaviour
 * read the WP_MEMORY_LIMIT constant directly — 40 MB on most hosts — and
 * tripped immediately on cron startup memory.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\MemoryGuard;

final class MemoryGuardLimitTest extends TestCase {

	private string $original_ini = '';

	protected function setUp(): void {
		parent::setUp();
		$this->original_ini = (string) ini_get( 'memory_limit' );
	}

	protected function tearDown(): void {
		ini_set( 'memory_limit', $this->original_ini );
		parent::tearDown();
	}

	public function test_reads_runtime_ini_over_constant(): void {
		ini_set( 'memory_limit', '512M' );
		$guard = new MemoryGuard();
		$this->assertSame( 512 * 1024 * 1024, $guard->limit_bytes() );
	}

	public function test_unlimited_ini_disables_halt(): void {
		ini_set( 'memory_limit', '-1' );
		$guard = new MemoryGuard();
		$this->assertSame( -1, $guard->limit_bytes() );
		$this->assertFalse( $guard->should_halt() );
	}

	public function test_explicit_override_still_wins(): void {
		ini_set( 'memory_limit', '512M' );
		$guard = new MemoryGuard( 10 * 1024 * 1024, static fn(): int => 9 * 1024 * 1024 );
		$this->assertSame( 10 * 1024 * 1024, $guard->limit_bytes() );
		$this->assertTrue( $guard->should_halt() );
	}
}
