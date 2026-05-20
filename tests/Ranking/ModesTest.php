<?php
/**
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Ranking;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;
use SemanticPosts\Embeddings\Vector;
use SemanticPosts\Ranking\DiverseMixMode;
use SemanticPosts\Ranking\FreshFirstMode;
use SemanticPosts\Ranking\Mode;
use SemanticPosts\Ranking\ModeFactory;
use SemanticPosts\Ranking\MostRelevantMode;
use SemanticPosts\Ranking\RankingContext;
use SplFixedArray;

/** Simple in-memory RankingContext. */
final class ArrayContext implements RankingContext {
	/** @var array<int,SplFixedArray<int,float>> */
	public array $embeddings = array();

	/** @var array<int,int> */
	public array $ages_days = array();

	public function get_embedding( int $post_id ): ?SplFixedArray {
		return $this->embeddings[ $post_id ] ?? null;
	}

	public function get_age_days( int $post_id ): int {
		return $this->ages_days[ $post_id ] ?? 0;
	}
}

final class ModesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<int,float> Pre-sorted descending cosines.
	 */
	private function sample_cosines(): array {
		return array(
			10 => 0.95,
			11 => 0.90,
			12 => 0.85,
			13 => 0.80,
			14 => 0.75,
			15 => 0.70,
		);
	}

	public function test_most_relevant_returns_top_k_in_order(): void {
		$mode = new MostRelevantMode();
		$out  = $mode->rank( $this->sample_cosines(), 3, new ArrayContext() );

		$this->assertSame( array( 10, 11, 12 ), array_keys( $out ) );
		$this->assertSame( 0.95, $out[10] );
	}

	public function test_most_relevant_slug_is_stable(): void {
		$this->assertSame( 'most-relevant', ( new MostRelevantMode() )->slug() );
	}

	public function test_fresh_first_pins_featured_to_highest_cosine(): void {
		Filters\expectApplied( 'semantic_posts_recency_decay' )->andReturn( 180.0 );

		$ctx                  = new ArrayContext();
		$ctx->ages_days[10]   = 1000; // featured candidate but VERY old
		$ctx->ages_days[11]   = 0;
		$ctx->ages_days[12]   = 5;
		$ctx->ages_days[13]   = 30;
		$ctx->ages_days[14]   = 60;
		$ctx->ages_days[15]   = 100;

		$mode = new FreshFirstMode();
		$out  = $mode->rank( $this->sample_cosines(), 5, $ctx );

		// Featured MUST be post 10 (highest cosine) even though it's very old.
		$this->assertSame( 10, array_key_first( $out ) );
	}

	public function test_fresh_first_reorders_tail_by_recency_blend(): void {
		Filters\expectApplied( 'semantic_posts_recency_decay' )->andReturn( 30.0 ); // short decay

		// Cosines: post 11 = 0.9, post 12 = 0.85. With 30-day decay:
		// post 11 (age 100 days): 0.9 * exp(-100/30) ≈ 0.032
		// post 12 (age 0):        0.85 * exp(0)     = 0.85
		// → post 12 should rank ahead of post 11 in the tail.
		$ctx              = new ArrayContext();
		$ctx->ages_days[10] = 0;   // featured
		$ctx->ages_days[11] = 100; // old, was 2nd by cosine
		$ctx->ages_days[12] = 0;   // fresh, was 3rd by cosine

		$mode = new FreshFirstMode();
		$out  = $mode->rank(
			array(
				10 => 0.95,
				11 => 0.90,
				12 => 0.85,
			),
			3,
			$ctx
		);

		$ids = array_keys( $out );
		$this->assertSame( 10, $ids[0], 'Featured = highest cosine.' );
		$this->assertSame( 12, $ids[1], 'Fresh post 12 should beat stale post 11 in the tail.' );
		$this->assertSame( 11, $ids[2] );
	}

	public function test_diverse_mix_prefers_diverse_when_relevance_gap_is_small(): void {
		Filters\expectApplied( 'semantic_posts_mmr_lambda' )->andReturn( 0.7 );

		$ctx = new ArrayContext();
		// Featured (10) and post 11 are near-duplicate embeddings.
		// Post 12 is fully orthogonal (diverse).
		$ctx->embeddings[10] = SplFixedArray::fromArray( array( 1.0, 0.0, 0.0 ) );
		$ctx->embeddings[11] = SplFixedArray::fromArray( array( 0.95, 0.05, 0.0 ) );
		$ctx->embeddings[12] = SplFixedArray::fromArray( array( 0.0, 1.0, 0.0 ) );

		// Small cosine gap between 11 (0.55) and 12 (0.50) → diversity penalty
		// (0.3 * 0.95) outweighs relevance gain (0.7 * 0.05). MMR picks 12 next.
		$cosines = array(
			10 => 0.95,
			11 => 0.55,
			12 => 0.50,
		);

		$mode = new DiverseMixMode();
		$out  = $mode->rank( $cosines, 3, $ctx );

		$this->assertSame( 10, array_key_first( $out ) );
		$ids = array_keys( $out );
		$this->assertSame( 12, $ids[1], 'MMR should pick the diverse candidate over the near-duplicate when relevance gap is small.' );
	}

	public function test_diverse_mix_with_lambda_one_collapses_to_most_relevant(): void {
		Filters\expectApplied( 'semantic_posts_mmr_lambda' )->andReturn( 1.0 );

		$ctx                  = new ArrayContext();
		$ctx->embeddings[10]  = SplFixedArray::fromArray( array( 1.0, 0.0 ) );
		$ctx->embeddings[11]  = SplFixedArray::fromArray( array( 0.9, 0.1 ) );
		$ctx->embeddings[12]  = SplFixedArray::fromArray( array( 0.0, 1.0 ) );

		$mode = new DiverseMixMode();
		$out  = $mode->rank( array( 10 => 0.95, 11 => 0.90, 12 => 0.30 ), 3, $ctx );

		// λ=1 ⇒ same order as cosines.
		$this->assertSame( array( 10, 11, 12 ), array_keys( $out ) );
	}

	public function test_mode_factory_maps_slugs(): void {
		$f = new ModeFactory();
		$this->assertInstanceOf( MostRelevantMode::class, $f->make( Mode::MOST_RELEVANT ) );
		$this->assertInstanceOf( FreshFirstMode::class, $f->make( Mode::FRESH_FIRST ) );
		$this->assertInstanceOf( DiverseMixMode::class, $f->make( Mode::DIVERSE_MIX ) );
	}

	public function test_mode_factory_unknown_slug_defaults_to_most_relevant(): void {
		$this->assertInstanceOf( MostRelevantMode::class, ( new ModeFactory() )->make( 'whatever' ) );
	}

	public function test_all_modes_pin_featured_to_highest_cosine(): void {
		// NFR-QUAL-4 invariant — explicit regression guard.
		Filters\expectApplied( 'semantic_posts_recency_decay' )->andReturn( 180.0 );
		Filters\expectApplied( 'semantic_posts_mmr_lambda' )->andReturn( 0.7 );

		$ctx                 = new ArrayContext();
		$ctx->ages_days[10]  = 9999; // featured is OLD
		$ctx->embeddings[10] = SplFixedArray::fromArray( array( 1.0, 0.0 ) );
		$ctx->embeddings[11] = SplFixedArray::fromArray( array( 0.95, 0.05 ) );
		$ctx->embeddings[12] = SplFixedArray::fromArray( array( 0.0, 1.0 ) );

		$cosines = array( 10 => 0.95, 11 => 0.90, 12 => 0.50 );

		foreach ( array( new MostRelevantMode(), new FreshFirstMode(), new DiverseMixMode() ) as $mode ) {
			$out = $mode->rank( $cosines, 3, $ctx );
			$this->assertSame( 10, array_key_first( $out ), $mode->slug() . ' must pin featured to highest cosine.' );
		}
	}
}
