<?php

declare(strict_types=1);

namespace App\Broker;

/**
 * What the broker made of an upsert.
 */
final readonly class UpsertResult
{
    /**
     * @param int                      $status   the broker's HTTP status code, 207 if any batch was partly rejected
     * @param array<string, Rejection> $rejected why the broker rejected each entity, by entity id
     */
    public function __construct(
        public int $status,
        public array $rejected = [],
    ) {
    }
}
