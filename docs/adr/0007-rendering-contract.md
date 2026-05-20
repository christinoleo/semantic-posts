# ADR-0007 — Rendering contract (HTML structure, theme overrides, customization surface)

**Status:** Accepted
**Date:** 2026-05-20

## Context

The plugin's visible front-end output is the related-posts widget. Once owners and theme authors start adopting the plugin, the HTML structure, CSS classes, and template path become a public contract — changes break their custom CSS and template overrides. Establishing the contract early and stably matters more than picking the "ideal" markup.

The contract must:
- Render predictable, semantic HTML.
- Be overridable by themes without forking the plugin.
- Expose deep customization for power users via filters and actions.
- Match the v1 visual design: 1 featured item + 4 grid items.
- Carry diagnostic metadata that admins can inspect without exposing it to readers.

## Decision

### HTML structure

```html
<section class="semantic-posts" data-sp-source="semantic">
  <h2 class="semantic-posts-heading">You might also like</h2>

  <article class="semantic-posts-featured" data-sp-item-source="semantic">
    <a href="{permalink}">
      <img src="{thumbnail-large}" alt="{title}">
      <h3 class="semantic-posts-featured-title">{title}</h3>
      <p class="semantic-posts-featured-excerpt">{excerpt}</p>
    </a>
  </article>

  <ul class="semantic-posts-grid">
    <li class="semantic-posts-item" data-sp-item-source="semantic">
      <a href="{permalink}">
        <img src="{thumbnail-medium}" alt="{title}">
        <h4 class="semantic-posts-item-title">{title}</h4>
      </a>
    </li>
    <!-- 4 more <li>s -->
  </ul>
</section>
```

- `<section>` wraps the entire widget, classed `.semantic-posts`.
- `<article class="semantic-posts-featured">` is the hero card (Medium-style next-article treatment, larger thumbnail, includes excerpt).
- `<ul class="semantic-posts-grid">` holds items 2–K as a grid, smaller thumbnails, titles only.
- `data-sp-source` on the section indicates the overall Recommendation Source (`semantic` / `category-fallback` / `none`).
- `data-sp-item-source` on each item indicates per-item source (relevant when some items came from semantic, others from category-fallback padding).

### Images

- Featured uses WordPress's `large` size via `get_the_post_thumbnail($id, 'large')`.
- Grid items use `medium`.
- If a post has no featured image: `<img>` is omitted entirely. No placeholder image. The card collapses to title-only — cleaner than a generic gray placeholder.

### Theme overrides

Plugin ships `templates/related-posts.php`. Themes override by copying to `{theme}/semantic-posts/related-posts.php`. Same convention used by WooCommerce and other major plugins. Standard `locate_template()` lookup.

Filter `semantic_posts_template_path` allows custom locations for advanced setups.

### Customization filters and actions

**Filters:**
- `semantic_posts_heading_text` — the "You might also like" string (default: translatable via `__()`).
- `semantic_posts_excerpt_length` — characters in the featured excerpt (default 160).
- `semantic_posts_item_classes` — array of CSS classes on each `<li>` and the featured `<article>`. Allows theme-specific tweaks without template override.
- `semantic_posts_render_html` — final HTML string before output. Last-chance hook for complete replacement.

**Actions:**
- `semantic_posts_before_render` — fires before output, before any opening tag.
- `semantic_posts_after_render` — fires after closing `</section>`.

### Internationalization

All user-facing strings wrapped in `__()` / `_e()` against text domain `semantic-posts`. Heading text, "Read more" links if any, admin labels, error messages. `.pot` file shipped in `languages/`.

## Consequences

**Positive:**
- Public CSS contract is small and well-named — easy for themes to style.
- Single template file keeps override path obvious.
- Diagnostic data attributes enable admin debugging without leaking to readers.
- Filter/action surface lets power users customize without touching templates or core.

**Negative:**
- Once published, the class names and template path are essentially locked. Renaming `semantic-posts-featured` later breaks every theme that targets it.
- Title-only fallback for posts without thumbnails creates layout asymmetry. Acceptable, but some sites might want a placeholder option — addable later via filter without breaking the contract.
- The featured/grid split is opinionated. Themes that want all 5 items as equal cards must override the entire template.

**Reversibility cost:** Adding new classes, filters, or data attributes is non-breaking. Removing or renaming any of the above breaks downstream customizations. Treat the contract as additive-only after v1 ships.

## Future work (post-v1)

- Pre-built display variants (grid-only, list, sidebar-friendly) selectable via settings without template override.
- Lazy-loading images via native `loading="lazy"` already in v1; consider `loading="auto"` + intersection observer for non-supporting browsers if usage data warrants.
- Per-post type rendering (e.g., recipe cards for `recipe` CPT, product cards for `product`) as a Pro feature.
- ARIA / accessibility audit: ensure screen-reader announcement is correct for "you might also like" sections and hero vs grid item semantics.
