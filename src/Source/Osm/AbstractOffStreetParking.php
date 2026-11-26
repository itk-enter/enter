<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\Definition;

/**
 * Off-street parking facilities with disabled bays in Aarhus Municipality.
 *
 * The production source and the test source extend this class. Each one
 * adds only its #[Definition] attribute.
 */
abstract class AbstractOffStreetParking extends AbstractHandicapParking
{
    /**
     * The facility categories for the parking tag values.
     */
    private const array FACILITY_CATEGORY = [
        'surface' => 'parkingLot',
        'underground' => 'underground',
        'multi-storey' => 'parkingGarage',
    ];

    protected function supports(array $tags): bool
    {
        return !$this->isSingleBay($tags) && 'offStreet' === $this->siting($tags);
    }

    protected function getModel(array $tags): string
    {
        return self::OFF_STREET_PARKING;
    }

    protected function getKey(array $element): string
    {
        $type = $element['type'] ?? null;
        $id = $element['id'] ?? null;

        return \is_string($type) && \is_int($id) ? \sprintf('%s-%d', $type, $id) : '';
    }

    protected function buildNgsiEntity(array $element, array $tags, array $geometry, Wgs84Transformer $transformer): NgsiEntity
    {
        $parkingMode = $this->parkingMode($tags);

        return parent::buildNgsiEntity($element, $tags, $geometry, $transformer)
            ->setProperty('category', [...$this->siteCategory($tags), ...$this->facilityCategory($tags)])
            ->setProperty('totalSpotNumber', $this->reservedBays($tags))
            ->setProperty('parkingMode', null === $parkingMode ? null : [$parkingMode])
            ->additionalInformation([
                'surface' => trim((string) ($tags['surface'] ?? '')),
                'wheelchair' => trim((string) ($tags['wheelchair'] ?? '')),
            ]);
    }

    /**
     * Gets the facility category from the parking tag.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function facilityCategory(array $tags): array
    {
        $parking = $tags['parking'] ?? null;

        return \is_string($parking) && isset(self::FACILITY_CATEGORY[$parking]) ? [self::FACILITY_CATEGORY[$parking]] : [];
    }
}
