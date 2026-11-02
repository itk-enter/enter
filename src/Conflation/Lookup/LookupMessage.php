<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * A request to ask one lookup about one merged entity.
 *
 * The run sends the message after it publishes. The worker handles it with
 * LookupHandler.
 */
#[AsMessage('async')]
final readonly class LookupMessage
{
    /**
     * @param string               $entityId   the id of the merged entity
     * @param string               $contextUrl the JSON-LD context of the merged data set
     * @param class-string         $lookup     the lookup class
     * @param list<string>         $attributes the attributes that the lookup must supply
     * @param array<string, mixed> $location   the GeoJSON geometry of the entity when the run sent the message
     * @param string|null          $checkedAt  the observedAt of the check property when the run sent the message, or
     *                                         null if the lookup never checked the entity
     */
    public function __construct(
        public string $entityId,
        public string $contextUrl,
        public string $lookup,
        public array $attributes,
        public array $location,
        public ?string $checkedAt,
    ) {
    }
}
