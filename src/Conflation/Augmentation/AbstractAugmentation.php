<?php

declare(strict_types=1);

namespace App\Conflation\Augmentation;

use App\Conflation\MergedEntity;
use App\Conflation\Record;
use App\Conflation\StatedValue;

/**
 * Adds facts to merged entities from data sets that describe something else
 * at the same place.
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
     * Decides which augmenting records add which attributes to which merged
     * entities.
     *
     * The pipeline calls this once per run, after the defining records are
     * grouped and their conflicts settled. It passes the merged entities,
     * the records of every augmenting model, and the merge's augmenting
     * configuration. An implementation typically finds, for each entity, the
     * augmenting records at its location, and takes from them the attributes
     * their model supplies and the entity lacks.
     *
     * For example, with augmenting: ['ModelC' => ['surface']], a merged
     * bench A-1 standing inside ground-cover area C-5, which states
     * surface "grass", gets:
     *
     *     ['A-1' => ['surface' => [StatedValue('grass', 'C-5', 'data-set-c')]]]
     *
     * List every record that supplies an attribute to an entity; when they
     * state different values, the merge's conflict-resolution approaches
     * settle them. Leave out entities that get nothing.
     *
     * @param list<MergedEntity>          $entities          the merged entities, with their settled attributes and
     *                                                       location
     * @param list<Record>                $records           every record of the merge's augmenting models, from every
     *                                                       data set
     * @param array<string, list<string>> $attributesByModel the merge's augmenting key: for each augmenting model, the
     *                                                       attributes it may supply, such as
     *                                                       ['ModelC' => ['surface']]
     *
     * @return array<string, array<string, list<StatedValue>>> values to add, by entity id, then attribute name
     */
    abstract public function augment(array $entities, array $records, array $attributesByModel): array;
}
