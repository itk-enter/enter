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
     * @param int                      $count      the number of upserted entities
     * @param int                      $deleted    the number of deleted stale entities
     * @param int                      $status     the HTTP status code from the broker
     * @param string                   $brokerUrl  the broker URL
     * @param string                   $entityHash the hash of the upserted entities, from EntityHash::of()
     * @param array<string, Rejection> $rejected   the rejections, by entity id
     * @param list<string>             $addedIds   the ids of the entities that the broker did not have before the import
     * @param list<string>             $deletedIds the ids of the deleted stale entities
     */
    public function __construct(
        public int $count,
        public int $deleted,
        public int $status,
        public string $brokerUrl,
        public string $entityHash,
        public array $rejected = [],
        public array $addedIds = [],
        public array $deletedIds = [],
    ) {
    }
}
