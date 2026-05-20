<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Verification;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Crawler\NeighborStore;
use SemanticPosts\Verification\VerificationPass;
use SplFixedArray;

require_once __DIR__ . '/../Indexing/ColdStartProcessorTest.php';

use SemanticPosts\Tests\Indexing\InMemoryState;

/** NeighborStore stub that returns scripted related rows. */
final class StubNeighborStore extends NeighborStore {
	/** @var array<int,array<int,float>> */
	public array $related = array();
	public function read_related( int $post_id ): array {
		return $this->related[ $post_id ] ?? array();
	}
}

final class VerificationPassTest extends TestCase {

	/** @var array<int,SplFixedArray<int,float>> */
	private array $embeddings = array();

	/** @var int[] */
	private array $indexed_ids = array();

	/** @var int[] */
	private array $sample_ids = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->embeddings  = array();
		$this->indexed_ids = array();
		$this->sample_ids  = array();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param float[] $values
	 */
	private function vec( array $values ): SplFixedArray {
		$f = new SplFixedArray( count( $values ) );
		foreach ( $values as $i => $v ) {
			$f[ $i ] = $v;
		}
		return $f;
	}

	private function build( StubNeighborStore $store, int $now = 1000 ): VerificationPass {
		$state = new InMemoryState();
		return $this->buildWithState( $store, $state, $now );
	}

	private function buildWithState( StubNeighborStore $store, InMemoryState $state, int $now ): VerificationPass {
		return new VerificationPass(
			$state,
			$store,
			static fn(): int => $now,
			fn( int $size ) => array_slice( $this->sample_ids, 0, $size ),
			fn() => $this->indexed_ids,
			fn( int $pid ) => $this->embeddings[ $pid ] ?? null
		);
	}

	public function test_is_due_returns_true_when_no_state_yet(): void {
		$pass = $this->build( new StubNeighborStore() );
		$this->assertTrue( $pass->is_due() );
	}

	public function test_is_due_returns_false_when_next_due_in_future(): void {
		$state = new InMemoryState();
		$state->state['verification']['next_due'] = 2000;
		$pass = $this->buildWithState( new StubNeighborStore(), $state, 1000 );
		$this->assertFalse( $pass->is_due() );
	}

	public function test_empty_sample_writes_zero_mrd(): void {
		$state = new InMemoryState();
		$pass  = $this->buildWithState( new StubNeighborStore(), $state, 5000 );
		$out   = $pass->run();
		$this->assertSame( 0.0, $out['mrd'] );
		$this->assertFalse( $out['drift'] );
		$this->assertSame( 0, $out['sampled'] );
		$this->assertSame( 5000 + VerificationPass::PERIOD_SECONDS, $state->state['verification']['next_due'] );
	}

	public function test_zero_drift_when_graph_matches_brute_force(): void {
		// 4 indexed posts. Source = 1. Embeddings chosen so cosine ordering is 2>3>4.
		$this->embeddings = array(
			1 => $this->vec( array( 1.0, 0.0 ) ),
			2 => $this->vec( array( 0.9, 0.1 ) ),
			3 => $this->vec( array( 0.7, 0.3 ) ),
			4 => $this->vec( array( 0.2, 0.8 ) ),
		);
		$this->indexed_ids = array( 1, 2, 3, 4 );
		$this->sample_ids  = array( 1 );

		$store               = new StubNeighborStore();
		$store->related[ 1 ] = array( 2 => 0.9, 3 => 0.7, 4 => 0.2 );

		$state = new InMemoryState();
		$pass  = $this->buildWithState( $store, $state, 100 );

		$out = $pass->run();
		$this->assertEqualsWithDelta( 0.0, $out['mrd'], 0.001 );
		$this->assertFalse( $out['drift'] );
		$this->assertSame( 100, $state->state['verification']['last_run'] );
	}

	public function test_full_swap_yields_high_mrd(): void {
		// 5 candidates. Brute order = [2,3,4,5]. Graph reverses to [5,4,3,2].
		$this->embeddings = array(
			1 => $this->vec( array( 1.0, 0.0 ) ),
			2 => $this->vec( array( 0.95, 0.05 ) ),
			3 => $this->vec( array( 0.85, 0.15 ) ),
			4 => $this->vec( array( 0.65, 0.35 ) ),
			5 => $this->vec( array( 0.15, 0.85 ) ),
		);
		$this->indexed_ids = array( 1, 2, 3, 4, 5 );
		$this->sample_ids  = array( 1 );

		$store               = new StubNeighborStore();
		$store->related[ 1 ] = array( 5 => 0.99, 4 => 0.98, 3 => 0.97, 2 => 0.96 );

		$pass = $this->build( $store );
		$out  = $pass->run();
		$this->assertGreaterThanOrEqual( 1.5, $out['mrd'] );
		$this->assertTrue( $out['drift'] );
	}

	public function test_threshold_can_be_filtered(): void {
		Filters\expectApplied( 'semantic_posts_verification_threshold' )->andReturn( 0.5 );
		$pass = $this->build( new StubNeighborStore() );
		$this->assertSame( 0.5, $pass->threshold() );
	}

	public function test_corpus_smaller_than_sample_size_works(): void {
		$this->embeddings = array(
			1 => $this->vec( array( 1.0, 0.0 ) ),
			2 => $this->vec( array( 0.5, 0.5 ) ),
		);
		$this->indexed_ids = array( 1, 2 );
		$this->sample_ids  = array( 1, 2 ); // 2 < SAMPLE_SIZE.

		$store = new StubNeighborStore();
		$store->related[ 1 ] = array( 2 => 0.5 );
		$store->related[ 2 ] = array( 1 => 0.5 );

		$pass = $this->build( $store );
		$out  = $pass->run();
		$this->assertSame( 2, $out['sampled'] );
		$this->assertEqualsWithDelta( 0.0, $out['mrd'], 0.001 );
	}

	public function test_missing_embedding_for_source_post_skips_brute_force(): void {
		// Source missing embedding — brute_top_k returns empty.
		// Graph also empty → footrule is 0 → MRD stays 0.
		$this->indexed_ids = array( 1, 2 );
		$this->sample_ids  = array( 1 );
		$pass = $this->build( new StubNeighborStore() );
		$out  = $pass->run();
		$this->assertSame( 0.0, $out['mrd'] );
	}
}
