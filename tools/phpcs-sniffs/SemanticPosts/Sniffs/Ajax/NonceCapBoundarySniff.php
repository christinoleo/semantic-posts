<?php
/**
 * AR-12: every wp_ajax_* handler must enforce nonce + capability in its first 5 statements.
 *
 * Detection strategy: find any `add_action( 'wp_ajax_*', <callback> )` call and validate
 * the callback body. The callback may be:
 *   - a string function name: 'my_ajax_handler'
 *   - a method reference array: array( $this, 'method' ) or array( ClassName::class, 'method' )
 *   - a closure (rare; supported)
 *
 * Validation: within the resolved callback body, both `check_ajax_referer(...)` and
 * `current_user_can(...)` MUST appear as top-level statements among the first 5 statements.
 *
 * @package SemanticPosts\PHPCS
 */

namespace SemanticPosts\Sniffs\Ajax;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class NonceCapBoundarySniff implements Sniff {

	/**
	 * Maximum number of top-level statements allowed before the nonce/cap pair.
	 */
	private const STATEMENT_BUDGET = 5;

	/**
	 * @return array<int,int|string>
	 */
	public function register() {
		return array( T_STRING );
	}

	/**
	 * @param File $phpcs_file File being scanned.
	 * @param int  $stack_ptr  Token index.
	 */
	public function process( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();
		if ( 'add_action' !== strtolower( $tokens[ $stack_ptr ]['content'] ) ) {
			return;
		}

		// Confirm function call.
		$next = $phpcs_file->findNext( T_WHITESPACE, $stack_ptr + 1, null, true );
		if ( false === $next || T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] ) {
			return;
		}

		// Reject method calls.
		$prev = $phpcs_file->findPrevious( T_WHITESPACE, $stack_ptr - 1, null, true );
		if ( false !== $prev && in_array( $tokens[ $prev ]['code'], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			return;
		}

		$close_paren = $tokens[ $next ]['parenthesis_closer'] ?? null;
		if ( null === $close_paren ) {
			return;
		}

		// Read first argument — must be a literal starting with `wp_ajax_`.
		$first_arg_ptr = $phpcs_file->findNext( T_WHITESPACE, $next + 1, $close_paren, true );
		if ( false === $first_arg_ptr || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $first_arg_ptr ]['code'] ) {
			return;
		}
		$hook = trim( $tokens[ $first_arg_ptr ]['content'], "'\"" );
		if ( 0 !== strpos( $hook, 'wp_ajax_' ) ) {
			return;
		}

		// Find the callback (2nd argument).
		$comma_ptr = $phpcs_file->findNext( T_COMMA, $first_arg_ptr + 1, $close_paren );
		if ( false === $comma_ptr ) {
			return;
		}
		$callback_start = $phpcs_file->findNext( T_WHITESPACE, $comma_ptr + 1, $close_paren, true );
		if ( false === $callback_start ) {
			return;
		}

		$callback_function = $this->resolve_callback_method_name( $phpcs_file, $callback_start, $close_paren );
		if ( null === $callback_function ) {
			// Inline closure?
			if ( T_CLOSURE === $tokens[ $callback_start ]['code'] ) {
				$this->validate_closure( $phpcs_file, $callback_start, $hook );
			}
			// Otherwise: dynamic callback (variable, etc.) — too dynamic to enforce.
			return;
		}

		$method_open = $this->find_method_body_in_file( $phpcs_file, $callback_function );
		if ( null === $method_open ) {
			// Method defined elsewhere; cross-file resolution is out of scope for v0.
			return;
		}

		$this->validate_body_first_statements( $phpcs_file, $method_open, $hook, $callback_function );
	}

	/**
	 * Resolve a callback expression to a method/function name string.
	 *
	 * Handles:
	 *   - 'my_func'
	 *   - array( $this, 'method' )
	 *   - array( self::class, 'method' )
	 *   - [ $this, 'method' ]
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $start_ptr  Token index where callback expression starts.
	 * @param int  $end_ptr    Token index of the closing paren of add_action.
	 * @return string|null Method/function name, or null if not statically resolvable.
	 */
	private function resolve_callback_method_name( File $phpcs_file, $start_ptr, $end_ptr ) {
		$tokens = $phpcs_file->getTokens();

		// Simple string callable: 'my_func'.
		if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $start_ptr ]['code'] ) {
			return trim( $tokens[ $start_ptr ]['content'], "'\"" );
		}

		// Array callable: array(...) or [...]. Find the second string literal inside.
		$array_open  = null;
		$array_close = null;
		if ( T_ARRAY === $tokens[ $start_ptr ]['code'] ) {
			$paren = $phpcs_file->findNext( T_OPEN_PARENTHESIS, $start_ptr + 1, $end_ptr );
			if ( false === $paren ) {
				return null;
			}
			$array_open  = $paren;
			$array_close = $tokens[ $paren ]['parenthesis_closer'];
		} elseif ( T_OPEN_SHORT_ARRAY === $tokens[ $start_ptr ]['code'] ) {
			$array_open  = $start_ptr;
			$array_close = $tokens[ $start_ptr ]['bracket_closer'];
		}

		if ( null === $array_open || null === $array_close ) {
			return null;
		}

		// Walk for second string literal (the method name).
		$strings_seen = 0;
		for ( $i = $array_open + 1; $i < $array_close; $i++ ) {
			if ( T_CONSTANT_ENCAPSED_STRING === $tokens[ $i ]['code'] ) {
				++$strings_seen;
				if ( 1 === $strings_seen ) {
					// In array($this, 'method') the method is the FIRST string. In array(self::class, 'method') it's also the first/only string.
					return trim( $tokens[ $i ]['content'], "'\"" );
				}
			}
		}
		return null;
	}

	/**
	 * Locate a method definition with the given name inside the current file.
	 *
	 * @param File   $phpcs_file File being scanned.
	 * @param string $method     Method or function name to find.
	 * @return int|null Token pointer of the opening `{`, or null if not found.
	 */
	private function find_method_body_in_file( File $phpcs_file, $method ) {
		$tokens = $phpcs_file->getTokens();
		$count  = count( $tokens );

		for ( $i = 0; $i < $count; $i++ ) {
			if ( T_FUNCTION !== $tokens[ $i ]['code'] ) {
				continue;
			}
			$name_ptr = $phpcs_file->findNext( T_STRING, $i + 1 );
			if ( false === $name_ptr ) {
				continue;
			}
			if ( $tokens[ $name_ptr ]['content'] !== $method ) {
				continue;
			}
			$brace_open = $tokens[ $i ]['scope_opener'] ?? null;
			if ( null !== $brace_open ) {
				return $brace_open;
			}
		}

		return null;
	}

	/**
	 * Validate a closure callback body directly.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $closure_ptr Token pointer of the T_CLOSURE.
	 * @param string $hook       Hook name (for error context).
	 */
	private function validate_closure( File $phpcs_file, $closure_ptr, $hook ) {
		$tokens     = $phpcs_file->getTokens();
		$brace_open = $tokens[ $closure_ptr ]['scope_opener'] ?? null;
		if ( null === $brace_open ) {
			return;
		}
		$this->validate_body_first_statements( $phpcs_file, $brace_open, $hook, 'closure' );
	}

	/**
	 * Check that both `check_ajax_referer` and `current_user_can` are present among
	 * the first STATEMENT_BUDGET top-level statements of a method body.
	 *
	 * @param File   $phpcs_file File being scanned.
	 * @param int    $brace_open Token pointer of the body's opening `{`.
	 * @param string $hook       Hook name (for error message).
	 * @param string $callback   Callback identifier (for error message).
	 */
	private function validate_body_first_statements( File $phpcs_file, $brace_open, $hook, $callback ) {
		$tokens      = $phpcs_file->getTokens();
		$brace_close = $tokens[ $brace_open ]['scope_closer'] ?? null;
		if ( null === $brace_close ) {
			return;
		}

		$has_nonce = false;
		$has_cap   = false;
		$stmts     = 0;
		$i         = $brace_open + 1;

		while ( $i < $brace_close && $stmts < self::STATEMENT_BUDGET ) {
			if ( T_WHITESPACE === $tokens[ $i ]['code'] || T_DOC_COMMENT === $tokens[ $i ]['code'] || T_COMMENT === $tokens[ $i ]['code']
				|| ( isset( $tokens[ $i ]['code'] ) && in_array( $tokens[ $i ]['code'], array( T_DOC_COMMENT_OPEN_TAG, T_DOC_COMMENT_CLOSE_TAG, T_DOC_COMMENT_STAR, T_DOC_COMMENT_WHITESPACE, T_DOC_COMMENT_STRING, T_DOC_COMMENT_TAG ), true ) ) ) {
				++$i;
				continue;
			}

			// Skip to end of this statement (`;`) to count it.
			$stmt_end = $phpcs_file->findNext( T_SEMICOLON, $i, $brace_close );
			if ( false === $stmt_end ) {
				break;
			}

			// Scan the statement for our required calls.
			for ( $j = $i; $j <= $stmt_end; $j++ ) {
				if ( T_STRING !== $tokens[ $j ]['code'] ) {
					continue;
				}
				$lower = strtolower( $tokens[ $j ]['content'] );
				if ( 'check_ajax_referer' === $lower ) {
					$has_nonce = true;
				} elseif ( 'current_user_can' === $lower ) {
					$has_cap = true;
				}
			}

			++$stmts;
			$i = $stmt_end + 1;
		}

		if ( ! $has_nonce || ! $has_cap ) {
			$missing = array();
			if ( ! $has_nonce ) {
				$missing[] = 'check_ajax_referer';
			}
			if ( ! $has_cap ) {
				$missing[] = 'current_user_can';
			}
			$phpcs_file->addError(
				sprintf(
					'AR-12 violation: hook "%s" callback (%s) must call %s within its first %d statements.',
					$hook,
					$callback,
					implode( ' + ', $missing ),
					self::STATEMENT_BUDGET
				),
				$brace_open,
				'MissingBoundaryCheck'
			);
		}
	}
}
