<?php
/**
 * Shared test double for StateRepository — avoids touching get_option / add_option.
 *
 * @package SemanticPosts\Tests
 */

declare( strict_types=1 );

namespace SemanticPosts\Tests\Indexing;

use SemanticPosts\Indexing\StateRepository;

class InMemoryState extends StateRepository {
	/** @var array<string,mixed> */
	public array $state;

	public function __construct() {
		$this->state = array(
			'cold_start'        => array(),
			'verification'      => array(),
			'dirty_queue_count' => 0,
			'metrics'           => array(
				'succeeded' => 0,
				'retried'   => 0,
				'failed'    => 0,
			),
			'failed_posts'      => array(),
		);
	}

	public function read(): array {
		return $this->state;
	}

	public function write( array $state ): void {
		$this->state = $state;
	}
}
