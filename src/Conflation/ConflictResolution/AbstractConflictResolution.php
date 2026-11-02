<?php

declare(strict_types=1);

namespace App\Conflation\ConflictResolution;

use App\Conflation\Data\StatedValue;

/**
 * One approach to settle an attribute that the members of a group disagree
 * on.
 *
 * A merge lists the approaches that it uses in its #[Conflation] attribute.
 * The pipeline tries them in order until one settles the conflict. If a kind
 * of thing needs rules of its own, the merge adds a subclass to the list. The
 * subclass can apply to all the attributes or only to some, and leaves the
 * rest to the next approach.
 *
 * For example, data set A states the wheelchair attribute as "yes" and data
 * set B states "no". The pipeline calls:
 *
 *     resolve('wheelchair', [StatedValue('yes', 'A-17', 'data-set-a'), StatedValue('no', 'B-903', 'data-set-b')])
 *
 * An approach that trusts data set B returns "no". An approach that applies
 * only to opening hours returns null.
 */
abstract class AbstractConflictResolution
{
    /**
     * Settles one attribute in conflict, or leaves it to the next approach.
     *
     * The pipeline calls this only if the members of a group state different
     * values for one attribute. If the values agree, the pipeline keeps them
     * and does not ask an approach. A member that does not state the
     * attribute is not among the values. Each value holds the stated value,
     * the record that stated it, and the data set of that record. Thus an
     * approach can decide by the value, by its origin, or by both.
     *
     * Return the value to keep, or null to leave the attribute to the next
     * approach in the list of the merge. If no approach settles it, the
     * pipeline leaves the attribute out of the merged entity and records its
     * values as a conflict.
     *
     * The pipeline settles the location in the same way, under the attribute
     * name ConflictResolver::LOCATION, with GeoJSON geometries as values.
     *
     * @param string            $attribute the name of the attribute in conflict, as the records state it
     * @param list<StatedValue> $values    at least two, not all the same, ordered by data set, then record
     *
     * @return mixed the value to keep, or null to leave it to the next approach
     */
    abstract public function resolve(string $attribute, array $values): mixed;
}
