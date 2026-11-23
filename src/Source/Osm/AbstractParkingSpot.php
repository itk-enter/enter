<?php

declare(strict_types=1);

namespace App\Source\Osm;

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
    protected function supports(array $tags): bool
    {
        return $this->isSingleBay($tags);
    }

    protected function getModel(array $tags): string
    {
        return self::PARKING_SPOT;
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
            ->setProperty('status', 'unknown')
            ->setProperty('category', $this->category($tags))
            ->additionalInformation([
                'fee' => trim((string) ($tags['fee'] ?? '')),
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }

    /**
     * Gets the category of the bay from the location of its site, on or off the street.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>|null
     */
    private function category(array $tags): ?array
    {
        $siting = $this->siting($tags);

        return null === $siting ? null : [$siting];
    }
}
