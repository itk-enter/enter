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
 * Each record must be in exactly one group, and a group must hold at
 * most one record from each data set. If the groups break these limits, the
 * run fails and publishes nothing.
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
     * Decides which records describe the same thing.
     *
     * The pipeline calls this once per run with the records of every
     * defining model, from every data set, in no particular order. Each
     * record has an id, the data set that it comes from, and the point to
     * compare it by. The point is the position of a point, or the centroid of
     * an area. If the record has a full geometry, the record also holds it.
     *
     * Return the records sorted into groups, one group per real-world thing.
     * A record that matches nothing is a group of its own. For example, the
     * radius is 5 m. Toilet A-17 from data set A and toilet B-903 from data
     * set B are 3 m apart. Toilet A-18 has nothing near it. The result is:
     *
     *     [new Cluster([A-17, B-903]), new Cluster([A-18])]
     *
     * Put every record in exactly one group. Do not put two records of the
     * same data set in one group. If you do, the run fails.
     *
     * Some defining models of a merge can be different from its result model.
     * Then the implementation returns the records restated in the result
     * model: it renames the attributes and converts the values. Thus all the
     * steps after this step see only the result model. A restated record
     * keeps its id, its data set and its location. The default does not
     * restate records, so such a merge names a subclass of its own.
     *
     * @param list<Record> $records every record of the merge's defining models
     *
     * @return list<Cluster> the groups, each with one or more of the given records
     */
    abstract public function merge(array $records): array;
}
