<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use PHPUnit\Framework\TestCase;
use SemanticPosts\Indexing\MemoryGuard;

final class MemoryGuardTest extends TestCase {

	public function test_should_halt_false_well_under_threshold(): void {
		$g = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => 50 * 1024 * 1024 );
		$this->assertFalse( $g->should_halt() );
	}

	public function test_should_halt_true_above_threshold(): void {
		// 81% of 256 MB.
		$usage = (int) ( 0.81 * 256 * 1024 * 1024 );
		$g     = new MemoryGuard( 256 * 1024 * 1024, static fn(): int => $usage );
		$this->assertTrue( $g->should_halt() );
	}

	public function test_should_halt_exact_threshold_returns_true(): void {
		$limit  = 256 * 1024 * 1024;
		$usage  = (int) ( 0.80 * $limit );
		$g      = new MemoryGuard( $limit, static fn(): int => $usage );
		$this->assertTrue( $g->should_halt(), 'Exactly at threshold counts as halt.' );
	}

	public function test_unlimited_memory_never_halts(): void {
		$g = new MemoryGuard( -1, static fn(): int => PHP_INT_MAX );
		$this->assertFalse( $g->should_halt() );
	}

	public function test_zero_limit_never_halts(): void {
		// Defensive: parse_size returns 0 for empty inputs.
		$g = new MemoryGuard( 0, static fn(): int => PHP_INT_MAX );
		$this->assertFalse( $g->should_halt() );
	}
}
