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
 * City-kiosk public toilets in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:mtm_spatialmaps-toilet-city',
    title: 'Test: Bytoiletter, Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-toilet-city',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://schema.org/docs/jsonldcontext.json',
    omittedFields: [
        'familie' => 'Category designation; constant "Toilet" throughout the export, redundant with the model every entity is published under.',
        'subfamilie' => 'Product designation; constant "TOI Cox" throughout the export.',
        'postnr_' => 'Administrative postal code; the address already identifies the location.',
        'by_' => 'Administrative city name; the address already identifies the location.',
        'kommune' => 'Constant "Aarhus" throughout the export, the data set\'s own scope.',
        'distrikt' => 'Internal municipal maintenance district, not a fact about the toilet.',
        'northing' => 'Stated in a different, unlabelled projection than the primary geometry and does not agree with it once reprojected; frequently absent.',
        'easting_westing' => 'Stated in a different, unlabelled projection than the primary geometry and does not agree with it once reprojected; frequently absent.',
        'oprettet_af' => 'Directory username of the municipal employee who created the record; personal data, and not a fact about the toilet.',
        'rettet_af' => 'Directory username of the municipal employee who last edited the record; personal data, and not a fact about the toilet.',
        'mi_style' => 'MapInfo rendering style.',
    ],
    dataUrlBase: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=by_toiletter',
)]
final class TestToiletCity extends AbstractSource
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
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-city-%s', $this->definition->model, $key),
            $this->definition->model
        );

        return $entity
            ->setProperty('name', $this->name($row))
            ->setProperty('address', trim((string) ($row['adresse'] ?? '')))
            ->setProperty('source', $this->definition->accessUrl)
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))
            ->additionalInformation([
                'status' => trim((string) ($row['status'] ?? '')),
                // Not createdAt/modifiedAt: NGSI-LD reserves both, and a
                // broker drops them without reporting it.
                'registeredAt' => trim((string) ($row['oprettet_dato'] ?? '')),
                'updatedAt' => trim((string) ($row['rettet_dato'] ?? '')),
            ]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function name(array $row): string
    {
        $navn = trim((string) ($row['navn'] ?? ''));
        if ('' !== $navn) {
            return $navn;
        }

        $placeringsinfo = trim((string) ($row['placeringsinfo'] ?? ''));

        return '' !== $placeringsinfo ? $placeringsinfo : trim((string) ($row['adresse'] ?? ''));
    }
}
