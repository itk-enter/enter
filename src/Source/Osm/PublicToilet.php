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
 *
 * The feed's tagging is open-ended, so a record may carry tags beside the ones
 * mapped here. A value is published as the feed states it, including the
 * semicolon-separated lists some tags use for multiple values.
 */
#[Definition(
    id: 'osm-public-toilet',
    title: 'Offentlige toiletter (OpenStreetMap), Aarhus Kommune',
    description: 'Public toilets mapped in OpenStreetMap within Aarhus Municipality, with the wheelchair access they state.',
    publisher: 'OpenStreetMap contributors',
    contact: 'https://community.openstreetmap.org/',
    landingPage: 'https://wiki.openstreetmap.org/wiki/Tag:amenity%3Dtoilets',

    // The Overpass QL in the URL: within Aarhus Municipality (OSM
    // relation 1784663), select every element tagged as a toilet.
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
    models: ['PublicToilet'],
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/PublicToilet/v0.0.2/dataModel.PointOfInterest/context.jsonld',
    updateFrequency: 'continuous',
    licence: 'https://opendatacommons.org/licenses/odbl/1-0/',

    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        'building' => 'States that the toilet occupies a building of its own, and the building tags beside it describe its levels, material and roof; facts about the structure rather than the facility. 20% of records carry it.',
        'check_date' => 'When a mapper last verified the record, as do the check_date qualifiers beside it; describes the survey rather than the toilet. 34% of records carry it.',
        'source' => 'Where a mapper took the record from; describes the mapping, and the source this import records is the feed it read. 1% of records carry it.',
        'note' => 'Free-text remark addressed to other mappers, as is fixme. 2% of records carry it.',
        'fixme' => 'Free-text remark addressed to other mappers.',
        'roof' => 'Describes the building the toilet occupies rather than the facility, as the roof: qualifiers do.',
        'mapillary' => 'Identifier in an external street-imagery service, as is panoramax; a photograph of the place rather than a fact about it. 3% of records carry panoramax and 1% mapillary.',
        'panoramax' => 'Identifier in an external street-imagery service; see mapillary.',
    ],
)]
final class PublicToilet extends AbstractSource
{
    /**
     * Maps one feed record onto an NgsiEntity.
     *
     * @param array<string, mixed> $data Overpass JSON element
     */
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): ?NgsiEntity
    {
        $type = $data['type'] ?? null;
        $id = $data['id'] ?? null;

        // OSM ids are only unique per element type, so both are needed to
        // address the same object again on the next import.
        if (!\is_string($type) || !\is_int($id)) {
            return null;
        }

        $geometry = $this->geometry($data);
        if (null === $geometry) {
            return null;
        }

        $tags = \is_array($data['tags'] ?? null) ? $data['tags'] : [];

        $entity = new NgsiEntity(
            \sprintf('urn:ngsi-ld:%s:aarhus-toilet-osm-%s-%d', $this->definition->model(), $type, $id),
            $this->definition->model()
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

            // Two facts are stated by a general tag and a toilets: prefixed
            // refinement: the general one describes the place the record sits
            // on, which may be larger than the toilet, and the refinement
            // describes the toilet itself. The model describes the toilet, so
            // the refinement wins where both are stated.
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

            // Facility facts the model has no attribute for, carried as the
            // feed states them. A record carries only the tags it has.
            //
            // level, access and charge are carried here only when their value
            // does not fit the model's attribute: a toilet on several levels
            // ("0;1"), an access the model has no term for ("permit",
            // "private"), or a charge in more than one currency. operator is a
            // name, and the model's refOperator requires a reference to an
            // organisation entity that is not published. otherTags carries
            // every tag this mapping neither maps nor omits, under its OSM
            // key, so a tag a mapper adds later is not silently lost.
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
     * Tags this mapping reads.
     */
    private const array MAPPED_TAGS = [
        'name', 'description', 'website', 'opening_hours', 'fee', 'charge', 'access',
        'wheelchair', 'toilets:wheelchair', 'changing_table', 'toilets:changing_table',
        'toilets:disposal', 'toilets:position', 'unisex', 'male', 'female', 'level', 'supervised',
        'toilets:handwashing', 'handwashing:soap', 'toilets:hands_drying', 'drinking_water', 'shower',
        'toilets:menstrual_products', 'indoor', 'seasonal', 'toilets:paper_supplied', 'operator',
    ];

    /**
     * Tags left out on purpose, with the reason given in omittedFields: the
     * key itself, and every key it prefixes with a colon.
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
     * Some tags state several values separated by semicolons.
     *
     * @return list<string>
     */
    private function list(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(';', $value)), static fn (string $item): bool => '' !== $item));
    }

    /**
     * Only the tag's plain answers map; any other value states nothing, and
     * an absent tag is unknown rather than "no".
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
            // designated states that the toilet is built for wheelchair users,
            // which is more than yes and so still fully accessible.
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
     * unisex, male and female are separate yes/no tags. Only a "yes" names a
     * group the toilet is designated for; "no" rules one out without naming
     * another.
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
     * Each payment:<method>=yes tag names one accepted method.
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
     * charge states an amount and a currency in one value, e.g. "5 DKK".
     * Only a single amount in an ISO 4217 code maps; a charge in several
     * currencies ("5 DKR; 1€") does not fit the model's one amount.
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
     * The fee tag states whether using the toilet costs anything. Only its two
     * plain values map; an untagged or unrecognised value states nothing about
     * charging rather than assuming it is free.
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
     * The query requests "out center tags", so a way or relation carries only
     * a single representative point, never full vertex or bounds geometry.
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
