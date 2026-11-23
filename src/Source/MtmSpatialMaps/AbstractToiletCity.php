<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\Definition;

/**
 * City-kiosk public toilets in Aarhus Municipality.
 *
 * The production source and the test source extend this class. Each one
 * adds only its #[Definition] attribute.
 */
abstract class AbstractToiletCity extends AbstractSource
{
    /**
     * Makes an NgsiEntity from one feed record.
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
                'placement' => '' !== trim((string) ($row['navn'] ?? '')) ? trim((string) ($row['placeringsinfo'] ?? '')) : '',
                'registeredAt' => trim((string) ($row['oprettet_dato'] ?? '')),
                'updatedAt' => trim((string) ($row['rettet_dato'] ?? '')),
            ]);
    }

    /**
     * Returns the name of the toilet.
     *
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
     * Returns the postal address of the toilet.
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
