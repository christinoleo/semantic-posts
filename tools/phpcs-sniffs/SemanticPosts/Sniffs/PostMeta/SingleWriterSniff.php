<?php
/**
 * AR-10: Postmeta single-writer invariant.
 *
 * Flags any write to a `_sp_*` postmeta key from a class outside the
 * documented owner. Read-side access is allowed everywhere.
 *
 * Owners:
 *   _sp_embedding              => Vector
 *   _sp_related, _sp_inbound   => Crawler
 *   _sp_text_hash, _sp_dirty   => HashDiffDetector
 *
 * @package SemanticPosts\PHPCS
 */

namespace SemanticPosts\Sniffs\PostMeta;

use PHP_CodeSniffer\Files\File;
use PHP_CodeSniffer\Sniffs\Sniff;

class SingleWriterSniff implements Sniff {

	/**
	 * Postmeta-write functions the sniff inspects.
	 *
	 * @var string[]
	 */
	private const WRITE_FUNCTIONS = array(
		'update_post_meta',
		'add_post_meta',
		'delete_post_meta',
	);

	/**
	 * Map of meta key (string literal) to the single class allowed to write it.
	 *
	 * @var array<string,string>
	 */
	private const OWNERS = array(
		'_sp_embedding' => 'Vector',
		'_sp_related'   => 'Crawler',
		'_sp_inbound'   => 'Crawler',
		'_sp_text_hash' => 'HashDiffDetector',
		'_sp_dirty'     => 'HashDiffDetector',
	);

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
		$tokens   = $phpcs_file->getTokens();
		$function = strtolower( $tokens[ $stack_ptr ]['content'] );

		if ( ! in_array( $function, self::WRITE_FUNCTIONS, true ) ) {
			return;
		}

		// Ensure this is a function call (next non-whitespace is `(`).
		$next = $phpcs_file->findNext( T_WHITESPACE, $stack_ptr + 1, null, true );
		if ( false === $next || T_OPEN_PARENTHESIS !== $tokens[ $next ]['code'] ) {
			return;
		}

		// Reject method calls / object access — `$x->update_post_meta(...)` is not the WP function.
		$prev = $phpcs_file->findPrevious( T_WHITESPACE, $stack_ptr - 1, null, true );
		if ( false !== $prev && in_array( $tokens[ $prev ]['code'], array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION ), true ) ) {
			return;
		}

		$meta_key = $this->extract_second_string_argument( $phpcs_file, $next );
		if ( null === $meta_key ) {
			return;
		}

		// Only enforce on the namespaced `_sp_*` keys we own.
		if ( 0 !== strpos( $meta_key, '_sp_' ) ) {
			return;
		}

		// Unknown `_sp_*` key — defensive: flag so it gets reviewed.
		if ( ! isset( self::OWNERS[ $meta_key ] ) ) {
			$phpcs_file->addError(
				sprintf(
					'Unknown _sp_* postmeta key "%s" — add it to the AR-10 owner map before writing to it.',
					$meta_key
				),
				$stack_ptr,
				'UnknownKey'
			);
			return;
		}

		$enclosing_class = $this->find_enclosing_class_name( $phpcs_file, $stack_ptr );
		$expected_owner  = self::OWNERS[ $meta_key ];

		if ( $enclosing_class !== $expected_owner ) {
			$phpcs_file->addError(
				sprintf(
					'AR-10 violation: postmeta key "%s" may only be written by class %s, but write occurred in %s.',
					$meta_key,
					$expected_owner,
					( '' === $enclosing_class ) ? 'global scope' : $enclosing_class
				),
				$stack_ptr,
				'WrongOwner'
			);
		}
	}

	/**
	 * Walk the argument list and return the literal string at position 2 (1-indexed),
	 * or null if it is not a plain string literal.
	 *
	 * @param File $phpcs_file  File being scanned.
	 * @param int  $open_paren  Index of the opening parenthesis.
	 * @return string|null
	 */
	private function extract_second_string_argument( File $phpcs_file, $open_paren ) {
		$tokens      = $phpcs_file->getTokens();
		$close_paren = $tokens[ $open_paren ]['parenthesis_closer'] ?? null;
		if ( null === $close_paren ) {
			return null;
		}

		$arg_index = 1;
		$depth     = 0;
		for ( $i = $open_paren + 1; $i < $close_paren; $i++ ) {
			$code = $tokens[ $i ]['code'];

			if ( T_OPEN_PARENTHESIS === $code || T_OPEN_SQUARE_BRACKET === $code || T_OPEN_CURLY_BRACKET === $code || T_OPEN_SHORT_ARRAY === $code ) {
				++$depth;
				continue;
			}
			if ( T_CLOSE_PARENTHESIS === $code || T_CLOSE_SQUARE_BRACKET === $code || T_CLOSE_CURLY_BRACKET === $code || T_CLOSE_SHORT_ARRAY === $code ) {
				--$depth;
				continue;
			}
			if ( 0 === $depth && T_COMMA === $code ) {
				++$arg_index;
				continue;
			}

			if ( 2 !== $arg_index ) {
				continue;
			}

			if ( T_WHITESPACE === $code ) {
				continue;
			}

			// At this point we are inside the 2nd argument. Only accept a single string literal
			// (anything else — variable, concat, function call — is too dynamic to enforce here).
			if ( T_CONSTANT_ENCAPSED_STRING === $code ) {
				$raw = $tokens[ $i ]['content'];
				return trim( $raw, "'\"" );
			}
			return null;
		}

		return null;
	}

	/**
	 * Walk backwards to find the enclosing class name, if any.
	 *
	 * @param File $phpcs_file File being scanned.
	 * @param int  $stack_ptr  Token index.
	 * @return string Class name (unqualified) or '' if at global scope.
	 */
	private function find_enclosing_class_name( File $phpcs_file, $stack_ptr ) {
		$tokens = $phpcs_file->getTokens();
		if ( empty( $tokens[ $stack_ptr ]['conditions'] ) ) {
			return '';
		}

		foreach ( array_reverse( $tokens[ $stack_ptr ]['conditions'], true ) as $cond_ptr => $cond_code ) {
			if ( T_CLASS === $cond_code || T_ANON_CLASS === $cond_code ) {
				$name_ptr = $phpcs_file->findNext( T_STRING, $cond_ptr + 1 );
				if ( false !== $name_ptr ) {
					return $tokens[ $name_ptr ]['content'];
				}
			}
		}

		return '';
	}
}
