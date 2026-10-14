<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\StatedValue;

/**
 * A conflict-resolution approach: named data sets decide, in priority order.
 *
 * A merge lists the data sets it trusts, most trusted first. For an attribute
 * in conflict, the value of the earliest listed data set that states one is
 * kept. Values from data sets that are not listed never settle a conflict, so
 * a data set added to a model changes nothing until someone lists it.
 *
 * For example, with new SourceAuthority(['data-set-a', 'data-set-c']), a
 * toilet stated as "08–20" by data set A and "06–22" by data set B gets
 * "08–20". A toilet stated only by data sets B and D is left to the next
 * approach, or recorded as a conflict if there is none.
 *
 * Like every approach, it is optional: a merge that lists no approach leaves
 * every conflict unsettled and recorded.
 */
class SourceAuthority extends AbstractConflictResolution
{
    /**
     * @param list<string> $sourceIds the trusted data sets, by source identifier, most trusted first
     */
    public function __construct(
        public readonly array $sourceIds,
    ) {
        if ([] === $sourceIds) {
            throw new \InvalidArgumentException('Source authority needs at least one data set.');
        }

        if (\count(array_unique($sourceIds)) !== \count($sourceIds)) {
            throw new \InvalidArgumentException(\sprintf('Source authority lists a data set twice: %s.', implode(', ', $sourceIds)));
        }
    }

    public function resolve(string $attribute, array $values): mixed
    {
        foreach ($this->sourceIds as $sourceId) {
            $value = array_find($values, static fn (StatedValue $value): bool => $value->sourceId === $sourceId);
            if (null !== $value) {
                return $value->value;
            }
        }

        return null;
    }
}
