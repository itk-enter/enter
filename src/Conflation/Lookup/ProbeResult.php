<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

/**
 * The result of LookupProbe for one lookup.
 */
final readonly class ProbeResult
{
    /**
     * @param string                    $id           the id of the lookup
     * @param string|null               $transport    the Messenger transport of the lookup, or null if the run asks
     *                                                the lookup without the queue
     * @param array<string, mixed>|null $answer       the values that the service gave, or null if it gave no answer or
     *                                                failed
     * @param string|null               $error        the message of the exception, or null if the service is available
     * @param float                     $milliseconds the time of the request
     */
    public function __construct(
        public string $id,
        public ?string $transport,
        public ?array $answer,
        public ?string $error,
        public float $milliseconds,
    ) {
    }

    public function isAvailable(): bool
    {
        return null === $this->error;
    }
}
