<?php

declare(strict_types=1);

namespace App\Test\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Public toilets outside the city-kiosk network in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:mtm_spatialmaps-toilet-other',
    title: 'Test: Andre toiletter, Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-toilet-other',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://schema.org/docs/jsonldcontext.json',
    omittedFields: [
        'bookbar' => 'Bookable flag; constant "Nej" throughout the export.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record; personal data, and not a fact about the toilet.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record; personal data, and not a fact about the toilet.',
        'mi_style' => 'MapInfo rendering style.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=andre_toiletter',
)]
final class TestToiletOther extends AbstractSource
{
    /**
     * Maps one feed record onto an NgsiEntity.
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $row = $data['properties'] ?? null;
        $geometry = $data['geometry'] ?? null;

        if (!\is_array($row) || !\is_array($geometry)) {
            return null;
        }

        $key = $row['mi_prinx'] ?? null;
        if (null === $key || '' === $key) {
            return null;
        }

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-other-%s', $this->definition->model, $key),
            $this->definition->model
        );

        return $entity
            ->setProperty('name', trim((string) ($row['navn'] ?? '')))
            ->setProperty('description', trim((string) ($row['beskrivelse'] ?? '')))
            ->setProperty('address', trim((string) ($row['adresse'] ?? '')))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))
            ->additionalInformation([
                'accessType' => trim((string) ($row['type'] ?? '')),
                'season' => trim((string) ($row['saeson'] ?? '')),
                // Not createdAt/modifiedAt: NGSI-LD reserves both, and a
                // broker drops them without reporting it.
                'registeredAt' => trim((string) ($row['oprettet_dato'] ?? '')),
                'updatedAt' => trim((string) ($row['rettet_dato'] ?? '')),
            ]);
    }
}
