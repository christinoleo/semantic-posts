<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Lifecycle;

use PHPUnit\Framework\TestCase;
use SemanticPosts\Lifecycle\BackupFilter;

final class BackupFilterTest extends TestCase {

	public function test_default_returns_two_largest_keys_when_called_with_empty_array(): void {
		$filter = new BackupFilter();
		$result = $filter->default_excluded( array() );

		$this->assertSame( array( '_sp_embedding', '_sp_inbound' ), $result );
	}

	public function test_remaining_sp_keys_are_NOT_default_excluded(): void {
		// `_sp_related`, `_sp_text_hash`, `_sp_dirty` are intentionally backup-included
		// so a graceful restore leaves the user with at least the cached neighbor lists
		// and the hash-diff state.
		$filter = new BackupFilter();
		$result = $filter->default_excluded( array() );

		$this->assertNotContains( '_sp_related', $result );
		$this->assertNotContains( '_sp_text_hash', $result );
		$this->assertNotContains( '_sp_dirty', $result );
	}

	public function test_caller_passed_keys_are_preserved_and_merged(): void {
		$filter = new BackupFilter();
		$result = $filter->default_excluded( array( '_other_plugin_meta' ) );

		$this->assertContains( '_other_plugin_meta', $result );
		$this->assertContains( '_sp_embedding', $result );
		$this->assertContains( '_sp_inbound', $result );
	}

	public function test_duplicates_are_collapsed_preserving_caller_order(): void {
		$filter = new BackupFilter();
		$result = $filter->default_excluded( array( '_sp_embedding', '_other' ) );

		// Caller-provided _sp_embedding appears once; _other preserved; _sp_inbound appended.
		$this->assertSame( array( '_sp_embedding', '_other', '_sp_inbound' ), $result );
	}

	public function test_non_array_input_is_normalised_to_defaults(): void {
		$filter = new BackupFilter();
		$this->assertSame( array( '_sp_embedding', '_sp_inbound' ), $filter->default_excluded( null ) );
		$this->assertSame( array( '_sp_embedding', '_sp_inbound' ), $filter->default_excluded( 'string-not-array' ) );
		$this->assertSame( array( '_sp_embedding', '_sp_inbound' ), $filter->default_excluded( 42 ) );
	}

	public function test_non_string_array_entries_are_dropped(): void {
		$filter = new BackupFilter();
		$result = $filter->default_excluded( array( '_keep', 42, null, array( 'nested' ), '_also_keep' ) );

		$this->assertSame( array( '_keep', '_also_keep', '_sp_embedding', '_sp_inbound' ), $result );
	}

	public function test_constant_is_stable_public_contract(): void {
		// Backup-plugin authors may reference this constant directly.
		$this->assertSame( array( '_sp_embedding', '_sp_inbound' ), BackupFilter::DEFAULT_EXCLUDED );
	}
}
