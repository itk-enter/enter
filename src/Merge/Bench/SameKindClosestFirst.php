<?php

declare(strict_types=1);

namespace App\Merge\Bench;

use App\Conflation\Io\ConflationFetcher;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Spatial\Pair;

/**
 * Groups benches closest first, as the default does, but pairs a record only
 * with a record of the same kind.
 *
 * A bench and a picnic table often stand within the radius of each other.
 * Without this rule, the merge could join them into one merged entity. A
 * record that states no kind pairs only with other records that state no
 * kind.
 */
final class SameKindClosestFirst extends OneToOneClosestFirst
{
    /**
     * The attribute that states the kind of a record, for example "bench" or "picnicTable".
     */
    public const string KIND = ConflationFetcher::ADDITIONAL_INFORMATION.ConflationFetcher::KEY_SEPARATOR.'kind';

    /**
     * The pairs within the radius whose records state the same kind.
     */
    protected function pairs(array $records): array
    {
        return array_values(array_filter(
            parent::pairs($records),
            static fn (Pair $pair): bool => ($pair->a->attributes[self::KIND] ?? null) === ($pair->b->attributes[self::KIND] ?? null),
        ));
    }
}
