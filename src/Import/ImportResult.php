<?php

declare(strict_types=1);

namespace App\Import;

/**
 * What one completed import did.
 */
final readonly class ImportResult
{
    /**
     * @param int    $count     entities sent to the broker
     * @param int    $status    the broker's HTTP status code
     * @param string $brokerUrl the broker they were sent to
     */
    public function __construct(
        public int $count,
        public int $status,
        public string $brokerUrl,
    ) {
    }
}
