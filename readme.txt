=== SemanticPosts ===
Contributors: christinoleo
Tags: related posts, embeddings, openai, semantic search, recommendations
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.0
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Related-posts widget powered by precomputed OpenAI embeddings. No render-time
API calls. Works on shared hosting.

== Description ==

SemanticPosts replaces category-only related-post widgets with semantic
recommendations. Embeddings are computed once at index time, stored in
postmeta, and served from cache on every request — **no third-party HTTP
during page rendering**.

= Key features =

* Auto-injects a "You might also like" widget after `the_content`, or via
  the `[semantic_posts]` shortcode.
* Three ranking modes: Most Relevant (default), Fresh First (recency-weighted),
  and Diverse Mix (MMR for topical variety).
* Single hourly cron tick drains the dirty queue, walks the Similarity Graph,
  and respects an 80%-of-`WP_MEMORY_LIMIT` budget so shared-hosting customers
  do not hit fatal OOMs.
* Settings page with cost-preview (USD estimate before you click "Index"),
  bulk-index button, observability panel, and a 14-EV registry showing the
  algorithm constants your site is using.
* WP-CLI: `wp semantic-posts {index|reindex|process-dirty|verify|retry-failed|status}`.
* AR-14: zero jQuery on the render path; admin assets are vanilla JS.
* AR-12: every AJAX endpoint verifies nonce + `manage_options` capability.

= Data sent to OpenAI =

Only indexable text (title × 3 + excerpt + cleaned content) is sent to the
OpenAI Embeddings API at index time. No user-personal data, no comment
contents, no analytics. Embeddings are returned as a 1536-dim float vector
and stored locally in `wp_postmeta`. See ADR-0003 for the storage format.

= Cost preview =

The Settings page displays a live USD estimate before you click "Index". The
default is 500 tokens/post (filter `semantic_posts_cost_avg_tokens_per_post`
to tune), priced at OpenAI's published rate:

* `text-embedding-3-small` — $0.020 per 1M tokens.
* `text-embedding-3-large` — $0.130 per 1M tokens.

A 5,000-post corpus typically embeds for ~$0.05 (small) or ~$0.33 (large).

= Host compatibility =

Tested on bare LAMP / LEMP, Bluehost-class shared hosting, and Redis-cached
managed hosts. SemanticPosts:

* Adds 0 outbound HTTP per page render (counted in the nightly benchmark).
* Adds ≤2 SQL queries per pageview (NFR-PERF-2).
* Adds <1 MB of peak memory per pageview (NFR-PERF-4).
* Tolerates `WP_CRON` being disabled — drive indexing via WP-CLI instead.

== Installation ==

1. Upload the plugin or install from the WordPress.org plugin directory.
2. Activate "SemanticPosts" through the **Plugins** screen.
3. Go to **Settings → SemanticPosts**.
4. Paste your OpenAI API key and click **Validate & save**.
5. Pick the post types and ranking mode.
6. Click **Start indexing**. The progress bar shows live status; safe to
   navigate away mid-run.

That's it. Frontend posts now render a "You might also like" block (or use
the `[semantic_posts]` shortcode where you want it).

== Frequently Asked Questions ==

= Does the plugin make API calls when visitors load a page? =

No. Embeddings are precomputed at index time and served from `wp_postmeta`
on every render. The nightly benchmark enforces zero outbound HTTP during
the_content rendering (NFR-PERF-3).

= What about my OpenAI bill? =

The Settings page shows a live cost estimate before you commit. Indexing
5,000 posts on the default `text-embedding-3-small` model is ~$0.05 of API
spend. The "Embedding cost (24h)" row on the observability panel tracks the
actual spend going forward.

= What data is sent to OpenAI? =

Only the indexable text of each post (title repeated 3× + manual excerpt +
HTML-stripped content, truncated to ~6500 words). No author data, no
comments, no analytics. See ADR-0001 for the exact composition rule.

= Does the plugin work with multilingual sites (Polylang / WPML)? =

Yes. Candidates are restricted to the source post's language when Polylang or
WPML is active. The `semantic_posts_disable_language_filter` filter overrides
when you want cross-language suggestions.

= What happens on uninstall? =

`uninstall.php` deletes every `_sp_*` postmeta row plus the
`semantic_posts_settings` and `_sp_state` options. Deactivation alone
preserves all data so deactivate / reactivate cycles are safe.

= What does the "backup filter" do? =

Backup plugins that respect the `semantic_posts_exclude_from_backup` filter
will skip the `_sp_embedding` and `_sp_inbound` rows on backup — those are
re-computable from text + the graph, and including them in backups bloats
archives. Turn this off by hooking the filter and returning an empty array.

= How do I run indexing without WP-Cron? =

`wp semantic-posts index` drains the corpus synchronously. The same code
path the cron tick uses — fully resumable across PHP restarts.

= I changed the embedding model — what happens? =

The Settings page shows a notice. Click **Wipe & re-index** to drop the old
embeddings and start fresh under the new model. Your settings, API key, and
metrics are preserved.

== Screenshots ==

1. Settings page — API key, model, count, ranking mode, cost preview.
2. Bulk-indexing modal with live progress bar.
3. Observability panel showing 24h metrics + EV registry.
4. Front-end widget rendered after the_content with featured + grid layout.

== Changelog ==

= 0.1.2 =
* Feature: GitHub-releases-based auto-updater. The plugin now reports
  updates inside `Dashboard → Updates` by checking the project's GitHub
  releases. No need for a WordPress.org listing — the standard "Update
  now" button installs the latest zip directly.

= 0.1.1 =
* Fix: the "Wipe & re-index" and "Start indexing" confirm dialogs now show
  the real estimated cost ("Continue? $0.0010.") instead of a literal "?"
  placeholder. Both flows now fetch the cost via the live AJAX endpoint
  using the currently-selected embedding model.

= 0.1.0 =
* Initial release: indexing pipeline, three ranking modes, settings UI,
  observability panel, MRD verification pass, WP-CLI surface, nightly
  benchmark workflow.

== Upgrade Notice ==

= 0.1.2 =
Adds auto-updates via GitHub releases. After this update the "Update now"
link in Dashboard → Updates will work without a WP.org listing.

= 0.1.1 =
Patch release — fixes a confusing "$?" in the bulk-index confirm dialog.
No data migration; safe to update in place.

= 0.1.0 =
First public release. After activation, paste your OpenAI key and click
"Start indexing".
