<?php

declare(strict_types=1);

namespace App\Conflation\MergeByLocation;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;

/**
 * Groups the records that describe the same real-world thing.
 *
 * A merge names an implementation in its #[Conflation] attribute. If the
 * default does not suit a kind of thing, the merge names a subclass. The
 * subclass replaces the default, or extends it: it calls the default and
 * adjusts its groups.
 *
 * For each implementation, the pipeline checks the groups that it returns.
 * Each record must be in exactly one group, itself or through the records
 * restated from it. A group must hold at most one record from each data set.
 * If the groups break these limits, the run fails and publishes nothing.
 *
 * For example, the radius is 5 m. Toilet A-17 from data set A and toilet
 * B-903 from data set B are 3 m apart. Toilet A-18 has nothing near it. Then
 * merge() returns:
 *
 *     [new Cluster([A-17, B-903]), new Cluster([A-18])]
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
     * The pipeline calls this once per run with the records of every
     * defining model, from every data set, in no particular order. Each
     * record has an id, the data set that it comes from, and the point to
     * compare it by. The point is the position of a point, or the centroid of
     * an area. If the record has a full geometry, the record also holds it.
     *
     * Return the records sorted into groups, one group per real-world thing.
     * A record that matches nothing is a group of its own.
     *
     * Put every record in exactly one group. Do not put two records of the
     * same data set in one group. If you do, the run fails.
     *
     * A record can also go into the groups as new records in the result
     * model. Set restatedFrom to its id, keep its data set, and keep each new
     * record within the radius of it.
     *
     * @param list<Record> $records every record of the merge's defining models
     *
     * @return list<Cluster> the groups, each with one or more of the given records
     */
    abstract public function merge(array $records): array;
}
