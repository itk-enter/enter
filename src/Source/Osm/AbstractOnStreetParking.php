<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Definition;

/**
 * On-street parking with disabled bays in Aarhus Municipality.
 *
 * The production source and the test source extend this class. Each one
 * adds only its #[Definition] attribute.
 */
abstract class AbstractOnStreetParking extends AbstractHandicapParking
{
    protected function supports(array $tags): bool
    {
        return !$this->isSingleBay($tags) && 'offStreet' !== $this->siting($tags);
    }

    protected function getModel(array $tags): string
    {
        return self::ON_STREET_PARKING;
    }

    protected function getKey(array $element): string
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        return \is_string($type) && \is_int($id) ? \sprintf('%s-%d', $type, $id) : '';
    }

    protected function buildNgsiEntity(array $element, array $tags, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        return parent::buildNgsiEntity($element, $tags, $geometry, $transformer)
            ->setProperty('category', $this->siteCategory($tags))
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('parkingMode', $this->parkingMode($tags))
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }
}
