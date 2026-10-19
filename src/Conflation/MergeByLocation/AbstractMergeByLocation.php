<?php

declare(strict_types=1);

namespace App\Conflation\MergeByLocation;

use App\Conflation\Cluster;
use App\Conflation\Record;

/**
 * Groups the records that describe the same real-world thing.
 *
 * A merge names an implementation in its #[Conflation] attribute. A kind the
 * default does not suit names a subclass that replaces the default, or
 * extends it by calling it and adjusting its groups.
 *
 * Whatever the rules, the pipeline checks the groups returned: every record
 * in exactly one group, and at most one record per data set in a group. A run
 * whose groups break that fails without publishing.
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
     * record has an id, the data set it comes from, the point it is compared
     * by (a point's own position, or the centroid of an area), and its full
     * geometry where it has one.
     *
     * Return the records sorted into groups, one group per real-world thing.
     * A record that matches nothing is a group of its own. For example, with
     * a radius of 5 m, toilet A-17 from data set A and toilet B-903 from data
     * set B lying 3 m apart, and toilet A-18 with nothing near it:
     *
     *     [new Cluster([A-17, B-903]), new Cluster([A-18])]
     *
     * Every record must be in exactly one group, and a group must not hold
     * two records of the same data set, or the run fails.
     *
     * A merge whose defining models are not all its result model returns its
     * records restated in the result model: attributes renamed and values
     * converted, so everything after this step sees the result model only. A
     * restated record keeps its id, its data set and its location. The default
     * does not restate records, so such a merge names a subclass of its own.
     *
     * @param list<Record> $records every record of the merge's defining models
     *
     * @return list<Cluster> the groups, each holding one or more of the given records
     */
    abstract public function merge(array $records): array;
}
