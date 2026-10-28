<?php

declare(strict_types=1);

namespace App\Conflation\Augmentation;

use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;

/**
 * Adds facts to merged entities from data sets that describe something else
 * at the same place.
 *
 * An implementation returns values, not entities, so it cannot create,
 * remove, regroup or move one. The pipeline checks the values that it
 * returns. Each value must be for an attribute that the merge names for the
 * model of its record, and for an entity that does not have the attribute.
 * Each value must also come from the augmenting records that the pipeline
 * gave. If several records supply different values for one attribute, the
 * conflict-resolution approaches of the merge settle it.
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
     * The pipeline calls this once per run, after it groups the defining
     * records and settles their conflicts. It passes the merged entities,
     * the records of every augmenting model, and the augmenting
     * configuration of the merge. Usually, an implementation finds the
     * augmenting records at the location of each entity. It takes from them
     * the attributes that their model supplies and that the entity does not
     * have.
     *
     * For example, with augmenting: ['ModelC' => ['surface']], a merged
     * bench A-1 that stands inside the ground-cover area C-5, which states
     * the surface "grass", gets:
     *
     *     ['A-1' => ['surface' => [StatedValue('grass', 'C-5', 'data-set-c')]]]
     *
     * List every record that supplies an attribute to an entity. If the
     * records state different values, the conflict-resolution approaches of
     * the merge settle them. Leave out the entities that get nothing.
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
