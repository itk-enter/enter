<?php

declare(strict_types=1);

namespace App\Import;

use App\Broker\Rejection;

/**
 * The result of one import.
 */
final readonly class ImportResult
{
    /**
     * @param int                      $count     the number of upserted entities
     * @param int                      $deleted   the number of deleted stale entities
     * @param int                      $status    the HTTP status code from the broker
     * @param string                   $brokerUrl the broker URL
     * @param array<string, Rejection> $rejected  the rejections, by entity id
     */
    public function __construct(
        public int $count,
        public int $deleted,
        public int $status,
        public string $brokerUrl,
        public array $rejected = [],
    ) {
    }
}
