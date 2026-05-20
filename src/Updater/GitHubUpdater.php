<?php
/**
 * GitHub-releases-based auto-updater (TB-21).
 *
 * Until SemanticPosts is published in the wordpress.org plugin directory,
 * WP has no upstream to check for updates. This updater plugs that gap by
 * consulting `api.github.com/repos/{owner}/{repo}/releases/latest` and
 * injecting a synthetic update payload that the standard WP update flow
 * picks up.
 *
 * Coordinates with the `Update URI` header in `semantic-posts.php`: the
 * header opts out of the wordpress.org check on WP 5.8+ so no "no info"
 * notice appears in `Dashboard → Updates`.
 *
 * Response caching: the GitHub call is wrapped in a 12-hour transient so
 * the admin dashboard doesn't hammer the API (60 requests/hour anon limit).
 * Failed lookups (network down, 5xx, malformed) cache an empty result for
 * 10 minutes to throttle retries.
 *
 * @package SemanticPosts\Updater
 */

declare( strict_types=1 );

namespace SemanticPosts\Updater;

defined( 'ABSPATH' ) || exit;

class GitHubUpdater {

	public const TRANSIENT_KEY  = 'semantic_posts_github_release';
	public const CACHE_TTL_OK   = 43200;  // 12h.
	public const CACHE_TTL_FAIL = 600;    // 10m.

	/** @var string */
	private string $plugin_file;
	/** @var string */
	private string $current_version;
	/** @var string */
	private string $github_owner;
	/** @var string */
	private string $github_repo;

	/**
	 * @param string $plugin_file     Absolute path to the main plugin file.
	 * @param string $current_version Current plugin version (SEMANTIC_POSTS_VERSION).
	 * @param string $github_owner    GitHub repo owner.
	 * @param string $github_repo     GitHub repo name.
	 */
	public function __construct( string $plugin_file, string $current_version, string $github_owner, string $github_repo ) {
		$this->plugin_file     = $plugin_file;
		$this->current_version = $current_version;
		$this->github_owner    = $github_owner;
		$this->github_repo     = $github_repo;
	}

	/**
	 * Hooked on `pre_set_site_transient_update_plugins`. Injects an update
	 * payload when a newer release is available on GitHub.
	 *
	 * @param  mixed $transient Existing transient value.
	 * @return mixed
	 */
	public function check_for_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$latest = $this->fetch_latest_release();
		if ( null === $latest ) {
			return $transient;
		}
		if ( version_compare( $latest['version'], $this->current_version, '<=' ) ) {
			return $transient;
		}

		$plugin_slug = plugin_basename( $this->plugin_file );
		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		$transient->response[ $plugin_slug ] = (object) array(
			'id'           => $plugin_slug,
			'slug'         => 'semantic-posts',
			'plugin'       => $plugin_slug,
			'new_version'  => $latest['version'],
			'url'          => 'https://github.com/' . $this->github_owner . '/' . $this->github_repo,
			'package'      => $latest['zip_url'],
			'icons'        => array(),
			'banners'      => array(),
			'tested'       => '7.0',
			'requires_php' => '8.0',
		);
		return $transient;
	}

	/**
	 * Hooked on `plugins_api`. Provides the body of the "View details" modal
	 * triggered from the Plugins screen.
	 *
	 * @param  mixed  $result Default plugins_api result (false initially).
	 * @param  string $action plugins_api action name.
	 * @param  object $args   plugins_api args object.
	 * @return mixed
	 */
	public function plugins_api( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( ! isset( $args->slug ) || 'semantic-posts' !== $args->slug ) {
			return $result;
		}
		$latest = $this->fetch_latest_release();
		if ( null === $latest ) {
			return $result;
		}
		return (object) array(
			'name'          => 'SemanticPosts',
			'slug'          => 'semantic-posts',
			'version'       => $latest['version'],
			'author'        => '<a href="https://github.com/' . $this->github_owner . '">' . $this->github_owner . '</a>',
			'homepage'      => 'https://github.com/' . $this->github_owner . '/' . $this->github_repo,
			'requires'      => '6.0',
			'tested'        => '7.0',
			'requires_php'  => '8.0',
			'download_link' => $latest['zip_url'],
			'last_updated'  => $latest['published_at'],
			'sections'      => array(
				'description' => 'Related posts via semantic embeddings, precomputed at index time and served from postmeta cache.',
				'changelog'   => '<pre>' . esc_html( $latest['body'] ) . '</pre>',
			),
		);
	}

	/**
	 * Fetch the latest release metadata from GitHub. Cached for 12h on
	 * success and 10m on failure.
	 *
	 * @return array{version:string, zip_url:string, body:string, published_at:string}|null
	 */
	public function fetch_latest_release(): ?array {
		$cached = get_transient( self::TRANSIENT_KEY );
		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}
		if ( is_array( $cached ) && empty( $cached ) ) {
			// Negative cache hit — short-circuit.
			return null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . $this->github_owner . '/' . $this->github_repo . '/releases/latest',
			array(
				'timeout' => 5,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'SemanticPosts-Updater',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::TRANSIENT_KEY, array(), self::CACHE_TTL_FAIL );
			return null;
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::TRANSIENT_KEY, array(), self::CACHE_TTL_FAIL );
			return null;
		}

		$tag     = ltrim( (string) $body['tag_name'], 'v' );
		$zip_url = '';
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( ! is_array( $asset ) ) {
					continue;
				}
				$name = (string) ( $asset['name'] ?? '' );
				if ( preg_match( '/\.zip$/', $name ) ) {
					$zip_url = (string) ( $asset['browser_download_url'] ?? '' );
					break;
				}
			}
		}
		if ( '' === $tag || '' === $zip_url ) {
			set_transient( self::TRANSIENT_KEY, array(), self::CACHE_TTL_FAIL );
			return null;
		}

		$out = array(
			'version'      => $tag,
			'zip_url'      => $zip_url,
			'body'         => (string) ( $body['body'] ?? '' ),
			'published_at' => (string) ( $body['published_at'] ?? '' ),
		);
		set_transient( self::TRANSIENT_KEY, $out, self::CACHE_TTL_OK );
		return $out;
	}
}
