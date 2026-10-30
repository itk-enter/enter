<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\Data\StatedValue;

/**
 * A conflict-resolution approach: named data sets decide, in priority order.
 *
 * A merge lists the data sets that it trusts, most trusted first. For an
 * attribute in conflict, the approach keeps the value of the first listed
 * data set that states a value. Values from data sets that are not in the
 * list never settle a conflict. Thus a new data set of a model changes
 * nothing until the merge lists it.
 *
 * For example, a merge uses new SourceAuthority(['data-set-a', 'data-set-c']).
 * Data set A states "08–20" for a toilet, and data set B states "06–22". The
 * toilet gets "08–20". If only data sets B and D state a value for a toilet,
 * the approach leaves it to the next approach. If there is no next approach,
 * the pipeline records a conflict.
 *
 * Like every approach, it is optional: a merge that lists no approach leaves
 * every conflict unsettled and records it.
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
