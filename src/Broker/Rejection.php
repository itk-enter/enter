<?php

declare(strict_types=1);

namespace App\Broker;

/**
 * The reason why the broker rejected an entity.
 */
final readonly class Rejection
{
    /**
     * @param string               $reason the summary from the broker
     * @param array<string, mixed> $error  the problem details from the broker
     */
    public function __construct(
        public string $reason,
        public array $error,
    ) {
    }
}
