<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Definition;

/**
 * Single disabled parking bays in Aarhus Municipality.
 *
 * The production source and the test source extend this class. Each one
 * adds only its #[Definition] attribute.
 */
abstract class AbstractParkingSpot extends AbstractHandicapParking
{
    protected function supports(array $properties): bool
    {
        return 1 === $this->bays($properties);
    }

    protected function getModel(array $properties): string
    {
        return self::PARKING_SPOT;
    }

    protected function getKey(array $properties): string
    {
        return (string) ($properties['mi_prinx'] ?? '');
    }

    protected function buildNgsiEntity(array $properties, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        return parent::buildNgsiEntity($properties, $geometry, $transformer)
            ->setProperty('status', 'unknown')
            ->setProperty('category', ['onStreet']);
    }
}
