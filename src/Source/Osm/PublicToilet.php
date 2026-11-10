<?php

declare(strict_types=1);

namespace App\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;

/**
 * Public toilets in Aarhus Municipality.
 */
#[Definition(
    id: 'osm-public-toilet',
    title: 'Offentlige toiletter (OpenStreetMap), Aarhus Kommune',
    description: 'Public toilets mapped in OpenStreetMap within Aarhus Municipality, with the wheelchair access they state.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Tag:amenity%3Dtoilets',

    accessUrl: [
        'url' => 'https://overpass-api.de/api/interpreter',
        'query' => [
            'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["amenity"="toilets"](area.a);
out center tags;
DATA,
        ],
    ],
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'PublicToilet',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        'building' => 'States that the toilet occupies a building of its own, and the building tags beside it describe its levels, material and roof; facts about the structure rather than the facility. 20% of records carry it.',
        'check_date' => 'When a mapper last verified the record, as do the check_date qualifiers beside it; describes the survey rather than the toilet. 34% of records carry it.',
        'source' => 'Where a mapper took the record from; describes the work of the mapper, and the source this import records is the feed it read. 1% of records carry it.',
        'note' => 'Free-text remark addressed to other mappers, as is fixme. 2% of records carry it.',
        'fixme' => 'Free-text remark addressed to other mappers.',
        'roof' => 'Describes the building the toilet occupies rather than the facility, as the roof: qualifiers do.',
        'mapillary' => 'Identifier in an external street-imagery service, as is panoramax; a photograph of the place rather than a fact about it. 3% of records carry panoramax and 1% mapillary.',
        'panoramax' => 'Identifier in an external street-imagery service; see mapillary.',
    ],
    dataset: 'osm-public-toilet',
    datasetTitle: 'Offentlige toiletter (OpenStreetMap), Aarhus Kommune',
)]
final class PublicToilet extends AbstractSource
{
    /**
     * Makes an NgsiEntity from one feed record.
     *
     * @param array<string, mixed> $data Overpass JSON element
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        if (!\is_string($type) || !\is_int($id)) {
            return null;
        }

        $geometry = $this->geometry($data);
        if (null === $geometry) {
            return null;
        }

        $tags = \is_array($data['tags'] ?? null) ? $data['tags'] : [];

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-osm-%s-%d', $this->definition->model, $type, $id),
            $this->definition->model
        );

        [$chargeAmount, $chargeCurrency] = $this->charge($tags);

        return $entity
            ->setProperty('name', $this->tag($tags, 'name'))
            ->setProperty('description', $this->tag($tags, 'description'))
            ->setProperty('seeAlso', $this->tag($tags, 'website'))
            ->setProperty('openingHours', $this->list($this->tag($tags, 'opening_hours')))
            ->setProperty('isAccessibleForFree', $this->isAccessibleForFree($tags))
            ->setProperty('chargeAmount', $chargeAmount)
            ->setProperty('chargeCurrency', $chargeCurrency)
            ->setProperty('paymentMethod', $this->paymentMethod($tags))
            ->setProperty('accessType', $this->accessType($tags))
            ->setProperty('wheelchairAccessible', $this->wheelchairAccessible(
                $this->tag($tags, 'toilets:wheelchair') ?: $this->tag($tags, 'wheelchair')
            ))
            ->setProperty('babyChange', $this->yesNo(
                $this->tag($tags, 'toilets:changing_table') ?: $this->tag($tags, 'changing_table')
            ))

            ->setProperty('disposal', $this->disposal($tags))
            ->setProperty('toiletPosition', $this->toiletPosition($tags))
            ->setProperty('genderCategory', $this->genderCategory($tags))
            ->setProperty('level', is_numeric($this->tag($tags, 'level')) ? (float) $this->tag($tags, 'level') : null)
            ->setProperty('staffed', $this->yesNo($this->tag($tags, 'supervised')))
            ->setProperty('handwashing', $this->yesNo($this->tag($tags, 'toilets:handwashing')))
            ->setProperty('soap', $this->yesNo($this->tag($tags, 'handwashing:soap')))
            ->setProperty('handDrying', $this->handDrying($tags))
            ->setProperty('drinkingWater', $this->yesNo($this->tag($tags, 'drinking_water')))
            ->setProperty('shower', $this->yesNo($this->tag($tags, 'shower')))
            ->setProperty('menstrualProducts', $this->yesNo($this->tag($tags, 'toilets:menstrual_products')))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))
            ->additionalInformation([
                'indoor' => $this->tag($tags, 'indoor'),
                'seasonal' => $this->tag($tags, 'seasonal'),
                'paperSupplied' => $this->tag($tags, 'toilets:paper_supplied'),
                'level' => is_numeric($this->tag($tags, 'level')) ? '' : $this->tag($tags, 'level'),
                'access' => null === $this->accessType($tags) ? $this->tag($tags, 'access') : '',
                'charge' => null === $chargeAmount ? $this->tag($tags, 'charge') : '',
                'operator' => $this->tag($tags, 'operator'),
                'otherTags' => $this->otherTags($tags) ?: null,
            ]);
    }

    /**
     * The tags that this source reads.
     */
    private const array MAPPED_TAGS = [
        'name', 'description', 'website', 'opening_hours', 'fee', 'charge', 'access',
        'wheelchair', 'toilets:wheelchair', 'changing_table', 'toilets:changing_table',
        'toilets:disposal', 'toilets:position', 'unisex', 'male', 'female', 'level', 'supervised',
        'toilets:handwashing', 'handwashing:soap', 'toilets:hands_drying', 'drinking_water', 'shower',
        'toilets:menstrual_products', 'indoor', 'seasonal', 'toilets:paper_supplied', 'operator',
    ];

    /**
     * The tags that this source does not use, and all the keys that start with these tags.
     */
    private const array OMITTED_TAGS = [
        'amenity', 'building', 'roof', 'check_date', 'source', 'note', 'fixme', 'mapillary', 'panoramax',
    ];

    /**
     * @param array<string, mixed> $tags
     *
     * @return array<string, string>
     */
    private function otherTags(array $tags): array
    {
        $other = [];
        foreach ($tags as $key => $value) {
            $key = (string) $key;
            $base = explode(':', $key)[0];
            if (\in_array($key, self::MAPPED_TAGS, true)
                || str_starts_with($key, 'payment:')
                || \in_array($base, self::OMITTED_TAGS, true)) {
                continue;
            }

            $value = trim((string) $value);
            if ('' !== $value) {
                $other[$key] = $value;
            }
        }
        ksort($other);

        return $other;
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function tag(array $tags, string $key): string
    {
        return trim((string) ($tags[$key] ?? ''));
    }

    /**
     * Splits a semicolon-separated tag value into a list.
     *
     * @return list<string>
     */
    private function list(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(';', $value)), static fn (string $item): bool => '' !== $item));
    }

    /**
     * Changes a "yes" or "no" value to a boolean, or returns null.
     */
    private function yesNo(string $value): ?bool
    {
        return match ($value) {
            'yes' => true,
            'no' => false,
            default => null,
        };
    }

    private function wheelchairAccessible(string $value): ?string
    {
        return match ($value) {
            'yes', 'designated' => 'yes',
            'limited' => 'limited',
            'no' => 'no',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function accessType(array $tags): ?string
    {
        return match ($this->tag($tags, 'access')) {
            'yes', 'public' => 'public',
            'customers' => 'customers',
            'permissive' => 'permissive',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $tags
     */
    private function disposal(array $tags): ?string
    {
        return match ($this->tag($tags, 'toilets:disposal')) {
            'flush' => 'flush',
            'chemical' => 'chemical',
            'pitlatrine' => 'pitLatrine',
            'bucket' => 'bucket',
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function toiletPosition(array $tags): array
    {
        $positions = $this->list($this->tag($tags, 'toilets:position'));

        return array_values(array_intersect(['seated', 'squat', 'urinal'], $positions));
    }

    /**
     * Gets the gender groups that the toilet is for.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function genderCategory(array $tags): array
    {
        return array_values(array_filter(
            ['female', 'male', 'unisex'],
            fn (string $key): bool => 'yes' === $this->tag($tags, $key)
        ));
    }

    /**
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function handDrying(array $tags): array
    {
        $means = [
            'electric_hand_dryer' => 'electricHandDryer',
            'paper_towel' => 'paperTowel',
            'towel' => 'towel',
        ];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $value): ?string => $means[$value] ?? null,
            $this->list($this->tag($tags, 'toilets:hands_drying'))
        ))));
    }

    /**
     * Gets the payment methods that the toilet accepts.
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function paymentMethod(array $tags): array
    {
        $methods = [
            'payment:coins' => 'coins',
            'payment:cash' => 'cash',
            'payment:cards' => 'card',
            'payment:credit_cards' => 'card',
            'payment:debit_cards' => 'card',
            'payment:contactless' => 'contactless',
            'payment:app' => 'mobileApp',
        ];

        $accepted = array_filter(
            $methods,
            fn (string $key): bool => 'yes' === $this->tag($tags, $key),
            \ARRAY_FILTER_USE_KEY
        );

        return array_values(array_unique($accepted));
    }

    /**
     * Gets the charge amount and currency.
     *
     * @param array<string, mixed> $tags
     *
     * @return array{0: float|null, 1: string|null} [amount, currency]
     */
    private function charge(array $tags): array
    {
        if (!preg_match('/^(\d+(?:[.,]\d+)?)\s*([A-Z]{3})$/', $this->tag($tags, 'charge'), $matches)) {
            return [null, null];
        }

        return [(float) str_replace(',', '.', $matches[1]), $matches[2]];
    }

    /**
     * Tells if the toilet is free of charge.
     *
     * @param array<string, mixed> $tags
     */
    private function isAccessibleForFree(array $tags): ?bool
    {
        return match ($tags['fee'] ?? null) {
            'no' => true,
            'yes' => false,
            default => null,
        };
    }

    /**
     * Gets the location of the element as a GeoJSON point.
     *
     * @param array<string, mixed> $element
     *
     * @return array{type: string, coordinates: array{float, float}}|null
     */
    private function geometry(array $element): ?array
    {
        return match ($element['type'] ?? null) {
            'node' => $this->point($element['lon'] ?? null, $element['lat'] ?? null),
            'way', 'relation' => $this->point(
                $element['center']['lon'] ?? null,
                $element['center']['lat'] ?? null
            ),
            default => null,
        };
    }

    /**
     * @return array{type: string, coordinates: array{float, float}}|null
     */
    private function point(mixed $longitude, mixed $latitude): ?array
    {
        if (!is_numeric($longitude) || !is_numeric($latitude)) {
            return null;
        }

        return ['type' => 'Point', 'coordinates' => [(float) $longitude, (float) $latitude]];
    }
}
