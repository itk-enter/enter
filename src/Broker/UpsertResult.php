<?php

declare(strict_types=1);

namespace App\Broker;

/**
 * The result of an upsert.
 */
final readonly class UpsertResult
{
    /**
     * @param int                      $status   the HTTP status code from the broker
     * @param array<string, Rejection> $rejected the rejections, by entity id
     */
    public function __construct(
        public int $status,
        public array $rejected = [],
    ) {
    }
}
