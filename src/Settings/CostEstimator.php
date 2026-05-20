<?php
/**
 * Estimate the USD cost of (re)embedding a corpus.
 *
 * Formula: posts × avg_tokens_per_post × price_per_token. The avg-tokens
 * default (500) is the rough middle of the IndexableTextBuilder envelope —
 * sites can tune via the `semantic_posts_cost_avg_tokens_per_post` filter
 * once they have observed traffic.
 *
 * @package SemanticPosts\Settings
 */

declare( strict_types=1 );

namespace SemanticPosts\Settings;

class CostEstimator {

	public const DEFAULT_AVG_TOKENS = 500;

	/**
	 * USD per million tokens, by model slug.
	 *
	 * @var array<string,float>
	 */
	private const PRICES_PER_MILLION = array(
		SettingsRepository::MODEL_SMALL => 0.020,
		SettingsRepository::MODEL_LARGE => 0.130,
	);

	/**
	 * @param int    $post_count Number of posts to embed.
	 * @param string $model      Embedding model slug.
	 *
	 * @return array{estimated_usd: float, total_tokens: int, model: string, posts: int}
	 */
	public function estimate( int $post_count, string $model ): array {
		$posts = max( 0, $post_count );

		$avg_tokens = (int) apply_filters(
			'semantic_posts_cost_avg_tokens_per_post',
			self::DEFAULT_AVG_TOKENS,
			$model,
			$posts
		);
		$avg_tokens = max( 1, $avg_tokens );

		$price_per_million = self::PRICES_PER_MILLION[ $model ] ?? self::PRICES_PER_MILLION[ SettingsRepository::MODEL_SMALL ];
		$total_tokens      = $posts * $avg_tokens;
		$cost              = ( $total_tokens / 1_000_000 ) * $price_per_million;

		return array(
			'estimated_usd' => round( $cost, 4 ),
			'total_tokens'  => $total_tokens,
			'model'         => $model,
			'posts'         => $posts,
		);
	}
}
