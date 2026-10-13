<?php

declare(strict_types=1);

namespace App\Conflation\Step;

use App\Conflation\StatedValue;

/**
 * One way of settling an attribute the members of a group disagree on
 * (ADR 006).
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
     * Stated values are never null, so null means "not settled".
     *
     * @param list<StatedValue> $values at least two, not all the same, ordered by data set, then record
     */
    abstract public function resolve(string $attribute, array $values): mixed;
}
