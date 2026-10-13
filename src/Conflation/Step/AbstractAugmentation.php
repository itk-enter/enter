<?php

declare(strict_types=1);

namespace App\Conflation\Step;

use App\Conflation\MergedEntity;
use App\Conflation\Record;
use App\Conflation\StatedValue;

/**
 * Adds facts to merged entities from data sets that describe something else
 * at the same place (ADR 007).
 *
 * An implementation returns values, not entities, so it cannot create,
 * remove, regroup or move one. The pipeline checks the values it returns:
 * only attributes the merge names for the supplying record's model, only on
 * entities that lack them, and only from the augmenting records given. Where
 * several records supply one attribute differently, the merge's
 * conflict-resolution approaches settle it.
 */
abstract class AbstractAugmentation
{
    /**
     * @param float $radius how far, in metres, a record given as a point reaches
     */
    public function __construct(
        public readonly float $radius,
    ) {
        if ($radius <= 0) {
            throw new \InvalidArgumentException(\sprintf('The radius must be positive, got %F.', $radius));
        }
    }

    /**
     * @param list<MergedEntity>          $entities          resolved, at their resolved location
     * @param list<Record>                $records           every record of the merge's augmenting models
     * @param array<string, list<string>> $attributesByModel the attributes each augmenting model supplies
     *
     * @return array<string, array<string, list<StatedValue>>> values to add, by entity id, then attribute
     */
    abstract public function augment(array $entities, array $records, array $attributesByModel): array;
}
