<?php
/**
 * PHPUnit bootstrap for SemanticPosts plugin tests.
 *
 * Uses Brain\Monkey for WordPress function stubbing — keeps unit tests fast and
 * isolated. WP-level integration tests (when added) will live under tests/Integration/
 * and require the full wp-tests-lib scaffold.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

// Plugin class files include a `defined( 'ABSPATH' ) || exit;` guard for
// `wp plugin check`. Define ABSPATH here so autoloading those classes during
// PHPUnit doesn't short-circuit.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// Bypass Freemius SDK during unit tests — it requires a full WP runtime and
// network access which the Brain\Monkey environment does not provide.
if ( ! defined( 'SEMANTIC_POSTS_BYPASS_FREEMIUS' ) ) {
	define( 'SEMANTIC_POSTS_BYPASS_FREEMIUS', true );
}

$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( ! file_exists( $autoload ) ) {
	fwrite( STDERR, "vendor/autoload.php missing — run `composer install` first.\n" );
	exit( 1 );
}
require_once $autoload;

// Default stub for WP's is_admin() so Bootstrap branches that gate admin-only
// wiring don't fatal in unit tests.
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin(): bool {
		return false;
	}
}


// Brain\Monkey is configured per-test via setUp/tearDown traits.
