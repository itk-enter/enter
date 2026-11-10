<?php

declare(strict_types=1);

namespace App\Import;

/**
 * Gets one hash for the upsert payload: all the entities that an import
 * sends to the broker.
 *
 * If two imports of a source give the same hash, they upserted the same
 * entities both times. The order of the entities has no effect on the hash,
 * because a feed can give its records in a different order each time.
 */
final class PayloadHash
{
    /**
     * @param list<array<string, mixed>> $payload the entities in the upsert payload, each with an "id"
     *
     * @return string the SHA-256 hash, as 64 hexadecimal characters
     */
    public static function of(array $payload): string
    {
        usort($payload, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return hash('sha256', json_encode(self::sortKeys($payload), \JSON_THROW_ON_ERROR));
    }

    /**
     * Sorts the keys of each JSON object in the value, at each level. A list
     * keeps its order.
     */
    private static function sortKeys(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        $value = array_map(self::sortKeys(...), $value);
        if (!array_is_list($value)) {
            ksort($value, \SORT_STRING);
        }

        return $value;
    }
}
