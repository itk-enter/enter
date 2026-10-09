<?php

declare(strict_types=1);

namespace App\Broker;

/**
 * Why the broker rejected an entity in a batch it accepted only in part.
 */
final readonly class Rejection
{
    /**
     * @param string               $reason the broker's own summary of the problem
     * @param array<string, mixed> $error  the problem details exactly as the broker gave them, for bug hunting
     */
    public function __construct(
        public string $reason,
        public array $error,
    ) {
    }
}
