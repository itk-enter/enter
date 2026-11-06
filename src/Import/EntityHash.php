<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Gets one hash for the entities that an import upserts.
 *
 * If two imports of a source give the same hash, the source gave the same
 * data both times. The order of the entities has no effect on the hash,
 * because a feed can give its records in a different order each time.
 */
final class EntityHash
{
    /**
     * @param list<array<string, mixed>> $payload the entities in the upsert payload, each with an "id"
     *
     * @return string the SHA-256 hash, as 64 hexadecimal characters
     */
    public static function of(array $payload): string
    {
        usort($payload, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return hash('sha256', json_encode($payload, \JSON_THROW_ON_ERROR));
    }
}
