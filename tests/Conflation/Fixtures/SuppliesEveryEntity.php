<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Augmentation\AbstractAugmentation;
use App\Conflation\StatedValue;

/**
 * Lets every augmenting record supply its model's attributes to every
 * merged entity that lacks them, whatever their location, so pipeline tests
 * decide what is supplied without areas.
 */
final class SuppliesEveryEntity extends AbstractAugmentation
{
    public function augment(array $entities, array $records, array $attributesByModel): array
    {
        $supplied = [];
        foreach ($entities as $entity) {
            foreach ($records as $record) {
                foreach ($attributesByModel[$record->model] ?? [] as $attribute) {
                    if (isset($record->attributes[$attribute]) && !$entity->has($attribute)) {
                        $supplied[$entity->id][$attribute][] = new StatedValue($record->attributes[$attribute], $record->id, $record->sourceId);
                    }
                }
            }
        }

        return $supplied;
    }
}
