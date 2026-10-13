<?php

declare(strict_types=1);

namespace App\Conflation\Step;

use App\Conflation\Cluster;
use App\Conflation\Record;

/**
 * Groups the records that describe the same real-world thing (ADR 005).
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
     * @param list<Record> $records every record of the merge's defining models
     *
     * @return list<Cluster>
     */
    abstract public function merge(array $records): array;
}
