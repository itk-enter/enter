<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\Data\StatedValue;

/**
 * A conflict-resolution approach: the value that most members state wins, if
 * more than half of them state it.
 *
 * Each member of a group comes from its own data set, so this counts data
 * sets. The approach compares the values as stated, so "Yes" and "yes" are
 * different values. If there is no majority, the approach leaves the
 * attribute to the next approach. If there is no next approach, the pipeline
 * records a conflict.
 *
 * For example, data sets A and B state the wheelchair attribute of a toilet
 * as "yes", and data set C states "no". The toilet gets "yes". These values
 * have no majority:
 *
 * - "yes" from A and "no" from B.
 * - "yes", "no", "limited" from three data sets.
 * - "yes", "yes", "no", "limited" from four data sets.
 *
 * List it before an approach that always decides, such as SourceAuthority,
 * so the data sets that agree settle a conflict first:
 *
 *     conflictResolution: [new MajorityWins(), new SourceAuthority(['data-set-a', 'data-set-b'])]
 */
class MajorityWins extends AbstractConflictResolution
{
    public function resolve(string $attribute, array $values): mixed
    {
        foreach ($values as $candidate) {
            $count = \count(array_filter($values, static fn (StatedValue $value): bool => $value->sameValueAs($candidate)));
            if (2 * $count > \count($values)) {
                return $candidate->value;
            }
        }

        return null;
    }
}
