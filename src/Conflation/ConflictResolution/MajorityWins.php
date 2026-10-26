<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\Data\StatedValue;

/**
 * A conflict-resolution approach: the value most members state wins, if more
 * than half of them state it.
 *
 * Each member of a group comes from its own data set, so this counts data
 * sets. Values are compared as stated, so "Yes" and "yes" are different
 * values. Without a majority, the attribute is left to the next approach, or
 * recorded as a conflict if there is none.
 *
 * For example, a toilet whose wheelchair attribute is stated as "yes" by data
 * sets A and B and as "no" by data set C gets "yes". Stated as "yes" by A and
 * "no" by B, it has no majority, and neither has "yes", "no", "limited" from
 * three data sets, nor "yes", "yes", "no", "limited" from four.
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
