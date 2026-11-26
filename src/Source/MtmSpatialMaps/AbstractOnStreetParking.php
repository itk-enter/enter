<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Definition;

/**
 * Locations with more than one disabled parking bay in Aarhus Municipality.
 *
 * The production source and the test source extend this class. Each one
 * adds only its #[Definition] attribute.
 */
abstract class AbstractOnStreetParking extends AbstractHandicapParking
{
    protected function supports(array $properties): bool
    {
        return 1 !== $this->bays($properties);
    }

    protected function getModel(array $properties): string
    {
        return self::ON_STREET_PARKING;
    }

    protected function getKey(array $properties): string
    {
        return (string) ($properties['mi_prinx'] ?? '');
    }

    protected function buildNgsiEntity(array $properties, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        return parent::buildNgsiEntity($properties, $geometry, $transformer)
            ->setProperty('category', ['forDisabled'])
            ->setProperty('totalSpotNumber', $this->bays($properties));
    }
}
