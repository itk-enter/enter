<?php

declare(strict_types=1);

namespace App\Import;

use App\Broker\Rejection;

/**
 * What one completed import did.
 */
final readonly class ImportResult
{
    /**
     * @param int                      $count     entities sent to the broker
     * @param int                      $deleted   entities of the source the import no longer yields, deleted from the broker
     * @param int                      $status    the broker's HTTP status code
     * @param string                   $brokerUrl the broker they were sent to
     * @param array<string, Rejection> $rejected  why the broker rejected each entity, by entity id
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
