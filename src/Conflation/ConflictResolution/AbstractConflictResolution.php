<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\StatedValue;

/**
 * One way of settling an attribute the members of a group disagree on.
 *
 * A merge lists the approaches it uses in its #[Conflation] attribute, and
 * they are tried in order until one settles the conflict. A kind that needs
 * rules of its own adds a subclass to the list; it can concern every
 * attribute or only some, and leaves the rest to the next approach.
 */
abstract class AbstractConflictResolution
{
    /**
     * Settles one attribute in conflict, or leaves it to the next approach.
     *
     * The pipeline calls this only when the members of a group state
     * different values for one attribute; values that agree are kept without
     * asking any approach. A member that does not state the attribute is not
     * among the values. Each value carries what was stated, the record that
     * stated it, and that record's data set, so an approach can decide by the
     * value, by where it comes from, or both.
     *
     * Return the value to keep, or null to leave the attribute to the next
     * approach in the merge's list. If no approach settles it, the attribute
     * is left out of the merged entity and its values are recorded as a
     * conflict. For example, when data set A states the wheelchair attribute
     * as "yes" and data set B as "no":
     *
     *     resolve('wheelchair', [StatedValue('yes', 'A-17', 'data-set-a'), StatedValue('no', 'B-903', 'data-set-b')])
     *
     * an approach trusting data set B returns "no", and one that only
     * concerns opening hours returns null.
     *
     * The location is settled the same way, under the attribute name
     * ConflictResolver::LOCATION, with GeoJSON geometries as values.
     *
     * @param string            $attribute the name of the attribute in conflict, as the records state it
     * @param list<StatedValue> $values    at least two, not all the same, ordered by data set, then record
     *
     * @return mixed the value to keep, or null to leave it to the next approach
     */
    abstract public function resolve(string $attribute, array $values): mixed;
}
