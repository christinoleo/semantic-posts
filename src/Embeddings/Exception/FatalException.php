<?php
/**
 * Thrown for non-retryable provider failures (4xx other than 429, malformed
 * response, missing API key). Callers should mark the job failed and stop
 * retrying until manual intervention.
 *
 * @package SemanticPosts\Embeddings\Exception
 */

declare( strict_types=1 );

namespace SemanticPosts\Embeddings\Exception;

class FatalException extends \RuntimeException {
}
