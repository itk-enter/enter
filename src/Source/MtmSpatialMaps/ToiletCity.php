<?php

declare(strict_types=1);

namespace App\Source\MtmSpatialMaps;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;

/**
 * City-kiosk public toilets in Aarhus Municipality.
 *
 * Geometry is a mix of Point and MultiPoint within this feed.
 */
#[Definition(
    id: 'mtm_spatialmaps-toilet-city',
    title: 'Bytoiletter, Aarhus Kommune',
    description: 'City-kiosk public toilets in Aarhus Municipality.',
    publisher: 'Aarhus Kommune',
    contact: 'ppg@aarhus.dk',
    landingPage: 'https://www.opendata.dk/city-of-aarhus',

    accessUrl: 'https://webkort.aarhuskommune.dk/spatialmap?page=get_geojson_opendata&datasource=by_toiletter',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:25832',
    model: 'PublicToilet',
    contextUrl: 'https://schema.org/docs/jsonldcontext.json',
    updateFrequency: 'continuous',

    // The portal states no licence for this data set. DCAT-AP requires one, so
    // it has to be settled with the data owner before the catalogue can be
    // registered anywhere.
    licence: null,

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
)]
final class ToiletCity extends AbstractSource
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

        // mi_prinx is the feed's stable primary key. Without it there is no
        // way to address the same record again on the next import, and an
        // upsert would create duplicates instead of updating.
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

            // The lifecycle flag and the register's own timestamps have no
            // counterpart on the model.
            //
            // The timestamps are not named createdAt and modifiedAt: NGSI-LD
            // reserves both for the entity's own system timestamps, and a
            // broker drops them from a payload without reporting it.
            ->additionalInformation([
                'status' => trim((string) ($row['status'] ?? '')),
                'registeredAt' => trim((string) ($row['oprettet_dato'] ?? '')),
                'updatedAt' => trim((string) ($row['rettet_dato'] ?? '')),
            ]);
    }

    /**
     * navn is blank on almost every record; placeringsinfo, when present,
     * names the specific spot better than the bare address.
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
}
