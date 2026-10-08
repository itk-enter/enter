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
    title: 'Test: Bytoiletter (MTM), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/webkort.aarhuskommune.dk/spatialmap?mtm_spatialmaps-toilet-city',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'familie' => 'Category designation; constant "Toilet" throughout the export, redundant with the model every entity is published under.',
        'subfamilie' => 'Product designation; constant "TOI Cox" throughout the export.',
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
            ->setProperty('address', $this->address($row))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))
            ->additionalInformation([
                'status' => trim((string) ($row['status'] ?? '')),
                'jcdNumber' => trim((string) ($row['jcd_nr_'] ?? '')),
                // Only when navn names the toilet; otherwise it is the name.
                'placement' => '' !== trim((string) ($row['navn'] ?? '')) ? trim((string) ($row['placeringsinfo'] ?? '')) : '',
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

    /**
     * The model's address is a structured postal address, which the feed
     * states as three separate fields.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function address(array $row): array
    {
        return array_filter([
            'streetAddress' => trim((string) ($row['adresse'] ?? '')),
            'postalCode' => trim((string) ($row['postnr_'] ?? '')),
            'addressLocality' => trim((string) ($row['by_'] ?? '')),
        ], static fn (string $value): bool => '' !== $value);
    }
}
