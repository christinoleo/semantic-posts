<?php
/**
 * Default related-posts template (ADR-0007 rendering contract).
 *
 * Themes override by copying to `{theme}/semantic-posts/related-posts.php`.
 * Variables provided by Renderer:
 *
 *   @var int[]  $sp_item_ids        Resolved post IDs.
 *   @var string $sp_data_source     'semantic' | 'category-fallback' | 'none'.
 *   @var string $sp_heading_text    Filterable heading (already filtered).
 *   @var int    $sp_excerpt_length  Filterable excerpt length (default 160).
 *   @var array  $sp_item_classes    Filterable item-level CSS classes.
 *   @var string $sp_thumbnail_size  Filterable thumbnail size (default 'large').
 *
 * @package SemanticPosts
 */

defined( 'ABSPATH' ) || exit;

if ( empty( $sp_item_ids ) ) {
	return;
}

$sp_featured_id = (int) array_shift( $sp_item_ids );
$sp_grid_ids    = array_slice( $sp_item_ids, 0, 4 );
?>
<section class="semantic-posts" data-sp-source="<?php echo esc_attr( $sp_data_source ); ?>">
	<h2 class="semantic-posts-heading"><?php echo esc_html( $sp_heading_text ); ?></h2>

	<article class="semantic-posts-featured <?php echo esc_attr( implode( ' ', $sp_item_classes ) ); ?>" data-sp-item-source="<?php echo esc_attr( $sp_data_source ); ?>">
		<a href="<?php echo esc_url( get_permalink( $sp_featured_id ) ); ?>">
			<?php
			if ( has_post_thumbnail( $sp_featured_id ) ) {
				echo get_the_post_thumbnail( $sp_featured_id, $sp_thumbnail_size ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
			<h3 class="semantic-posts-featured-title"><?php echo esc_html( get_the_title( $sp_featured_id ) ); ?></h3>
			<?php
			$sp_excerpt = wp_strip_all_tags( get_the_excerpt( $sp_featured_id ) );
			if ( '' !== $sp_excerpt ) {
				if ( function_exists( 'mb_substr' ) ) {
					$sp_excerpt_short = mb_substr( $sp_excerpt, 0, $sp_excerpt_length );
				} else {
					$sp_excerpt_short = substr( $sp_excerpt, 0, $sp_excerpt_length );
				}
				?>
				<p class="semantic-posts-featured-excerpt"><?php echo esc_html( $sp_excerpt_short ); ?></p>
				<?php
			}
			?>
		</a>
	</article>

	<?php if ( ! empty( $sp_grid_ids ) ) : ?>
		<ul class="semantic-posts-grid">
			<?php foreach ( $sp_grid_ids as $sp_grid_id ) : ?>
				<li class="semantic-posts-item <?php echo esc_attr( implode( ' ', $sp_item_classes ) ); ?>" data-sp-item-source="<?php echo esc_attr( $sp_data_source ); ?>">
					<a href="<?php echo esc_url( get_permalink( $sp_grid_id ) ); ?>">
						<?php
						if ( has_post_thumbnail( $sp_grid_id ) ) {
							echo get_the_post_thumbnail( $sp_grid_id, 'medium' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						}
						?>
						<h4 class="semantic-posts-item-title"><?php echo esc_html( get_the_title( $sp_grid_id ) ); ?></h4>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	<?php endif; ?>
</section>
