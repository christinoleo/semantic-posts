<?php
/**
 * Thrown for transient provider failures (5xx, 429, network timeout). Callers
 * should re-queue with exponential backoff per FR-3.
 *
 * @package SemanticPosts\Embeddings\Exception
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings\Exception;

class RetryableException extends \RuntimeException {
}
