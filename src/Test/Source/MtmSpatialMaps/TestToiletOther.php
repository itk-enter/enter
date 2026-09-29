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
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
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

        $description = trim((string) ($row['beskrivelse'] ?? ''));
        $access = trim((string) ($row['type'] ?? ''));

        return $entity
            ->setProperty('name', trim((string) ($row['navn'] ?? '')))
            ->setProperty('description', $description)
            ->setProperty('address', $this->address($row))
            ->setProperty('toiletType', $this->toiletType($description))
            ->setProperty('toiletPosition', $this->toiletPosition($description))
            ->setProperty('wheelchairAccessible', $this->wheelchairAccessible($description))
            ->setProperty('accessType', $this->accessType($access))
            ->setProperty('accessNote', $this->accessNote($access))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))

            // The season states no hours, so it cannot be restated as the
            // model's openingHours, and the register's own timestamps have no
            // counterpart on the model.
            //
            // The timestamps are not named createdAt and modifiedAt: NGSI-LD
            // reserves both for the entity's own system timestamps, and a
            // broker drops them from a payload without reporting it.
            ->additionalInformation([
                'season' => trim((string) ($row['saeson'] ?? '')),
                'registeredAt' => trim((string) ($row['oprettet_dato'] ?? '')),
                'updatedAt' => trim((string) ($row['rettet_dato'] ?? '')),
            ]);
    }

    /**
     * The feed states only the street address.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, string>
     */
    private function address(array $row): array
    {
        $street = trim((string) ($row['adresse'] ?? ''));

        return '' !== $street ? ['streetAddress' => $street] : [];
    }

    /**
     * beskrivelse names the kind of facility. Only the values that state
     * something the model has a term for map; the rest state nothing.
     */
    private function toiletType(string $description): ?string
    {
        // A rented cabin is placed for the season rather than built.
        return 'Indlejet toiletkabine' === $description ? 'portable' : null;
    }

    /**
     * @return list<string>
     */
    private function toiletPosition(string $description): array
    {
        return match ($description) {
            'Urinal' => ['urinal'],
            'Toilet og urinal' => ['seated', 'urinal'],
            default => [],
        };
    }

    private function wheelchairAccessible(string $description): ?string
    {
        // A record that is not described as a handicap toilet states nothing
        // about its accessibility, so it is left unknown rather than "no".
        return match ($description) {
            'Handicaptoilet', 'Multi Handicaptoilet' => 'yes',
            default => null,
        };
    }

    /**
     * Only "Fri" states who may use the toilet. The locked variants restrict
     * when and how it is entered, which the model has no term for.
     */
    private function accessType(string $access): ?string
    {
        return 'Fri' === $access ? 'public' : null;
    }

    /**
     * Any access value other than "Fri" describes a restriction, and is
     * carried as the feed states it.
     */
    private function accessNote(string $access): string
    {
        return 'Fri' === $access ? '' : $access;
    }
}
