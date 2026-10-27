<?php

declare(strict_types=1);

namespace App\Conflation\MergeByLocation;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;

/**
 * Groups the records that describe the same real-world thing.
 *
 * A merge names an implementation in its #[Conflation] attribute. A kind the
 * default does not suit names a subclass that replaces the default, or
 * extends it by calling it and adjusting its groups.
 *
 * Whatever the rules, the pipeline checks the groups returned: every record
 * in exactly one group, itself or through the records restated from it, and
 * at most one record per data set in a group. A run whose groups break that
 * fails without publishing.
 */
abstract class AbstractMergeByLocation
{
    /**
     * @param float $radius the furthest apart, in metres, two records of one thing can lie
     */
    public function __construct(
        public readonly float $radius,
    ) {
        if ($radius <= 0) {
            throw new \InvalidArgumentException(\sprintf('The radius must be positive, got %F.', $radius));
        }
    }

    /**
     * Sorts the records into groups. Each group is one real-world thing.
     *
     * Put each record in one group. A group must not hold two records from
     * the same data set.
     *
     * A record can also go into the groups as new records in the result
     * model. Set restatedFrom to its id, keep its data set, and keep each new
     * record within the radius of it.
     *
     * If the groups break these rules, the run stops.
     *
     * @param list<Record> $records every record of the merge's defining models
     *
     * @return list<Cluster> the groups, each holding one or more of the given records
     */
    abstract public function merge(array $records): array;
}
