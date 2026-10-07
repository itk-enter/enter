<?php

declare(strict_types=1);

namespace App\Test\Source\Osm;

use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Test\Source\TestDefinition;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * Public benches in Aarhus Municipality.
 */
#[When('dev')]
#[When('test')]
#[TestDefinition(
    // By convention the ID as a test source must start with `test:`
    id: 'test:osm-bench',
    title: 'Test: Bænke (OpenStreetMap), Aarhus Kommune',
    accessUrl: 'http://nginx:8080/test/data/overpass-api.de/api/interpreter?osm-bench',
    dataType: DataType::Overpass,
    mediaType: 'application/json',
    crs: 'EPSG:4326',
    model: 'Bench',
    contextUrl: 'https://raw.githubusercontent.com/itk-enter/data-models/Bench/v0.0.1/dataModel.PointOfInterest/context.jsonld',
    omittedFields: [
        'amenity' => 'Selector; every record is published under the one model this source names.',
        'access' => 'Every bench this model describes is public; the few records that carry it state "yes".',
        'source' => 'Where a mapper took the record from; describes the mapping, and the source this import records is the feed it read.',
        'note' => 'Free-text remark addressed to other mappers, as is fixme.',
        'fixme' => 'Free-text remark addressed to other mappers.',
        'not' => 'Hints to other mappers that a tag is deliberately absent, as the not: prefixed keys are; they state nothing about the bench.',
        'tourism' => 'Marks a bench that is also an artwork, as artwork_type and artist_name describe it; the artwork is better published as an entity of its own.',
        'artwork_type' => 'Describes the artwork a bench is, not the bench; see tourism.',
        'artist_name' => 'Describes the artwork a bench is, not the bench; see tourism.',
    ],
    dataUrlBase: 'https://overpass-api.de/api/interpreter',
    dataUrlQuery: [
        'data' => <<<'DATA'
[out:json][timeout:180];
area(3601784663)->.a;
nwr["amenity"="bench"](area.a);
out center tags;
DATA,
    ]
)]
final class TestBench extends AbstractSource
{
    /**
     * Tags this mapping reads.
     */
    private const array MAPPED_TAGS = [
        'name', 'description', 'backrest', 'armrest', 'seats', 'seats:separated', 'material', 'colour',
        'direction', 'two_sided', 'lying_down', 'hostile_architecture', 'covered', 'lit', 'bin',
        'inscription', 'memorial', 'level', 'check_date', 'survey:date', 'panoramax',
    ];

    /**
     * Tags left out on purpose, with the reason given in omittedFields: the
     * key itself, and every key it prefixes with a colon.
     */
    private const array OMITTED_TAGS = [
        'amenity', 'access', 'source', 'note', 'fixme', 'not', 'tourism', 'artwork_type', 'artist_name',
    ];

    /**
     * The materials the model has a term for; OSM and the model use the same
     * words.
     */
    private const array MATERIALS = ['wood', 'metal', 'steel', 'concrete', 'stone', 'granite', 'plastic'];

    /**
     * The 16 compass points OSM allows for direction, in degrees clockwise
     * from north.
     */
    private const array COMPASS_POINTS = [
        'N' => 0.0, 'NNE' => 22.5, 'NE' => 45.0, 'ENE' => 67.5,
        'E' => 90.0, 'ESE' => 112.5, 'SE' => 135.0, 'SSE' => 157.5,
        'S' => 180.0, 'SSW' => 202.5, 'SW' => 225.0, 'WSW' => 247.5,
        'W' => 270.0, 'WNW' => 292.5, 'NW' => 315.0, 'NNW' => 337.5,
    ];

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
            \sprintf('urn:ngsi-ld:%s:aarhus-bench-osm-%s-%d', $this->definition->model, $type, $id),
            $this->definition->model
        );

        $material = $this->tag($tags, 'material');
        $direction = $this->direction($this->tag($tags, 'direction'));
        $level = $this->tag($tags, 'level');

        return $entity
            ->setProperty('name', $this->tag($tags, 'name'))
            ->setProperty('description', $this->tag($tags, 'description'))
            ->setProperty('backrest', $this->yesNo($this->tag($tags, 'backrest')))
            ->setProperty('armrest', $this->yesNo($this->tag($tags, 'armrest')))
            ->setProperty('seats', $this->seats($this->tag($tags, 'seats')))
            ->setProperty('seatsSeparated', $this->yesNo($this->tag($tags, 'seats:separated')))
            ->setProperty('material', \in_array($material, self::MATERIALS, true) ? $material : null)
            ->setProperty('color', $this->tag($tags, 'colour'))
            ->setProperty('direction', $direction)
            ->setProperty('twoSided', $this->yesNo($this->tag($tags, 'two_sided')))
            ->setProperty('lyingDown', $this->yesNo($this->tag($tags, 'lying_down')))
            ->setProperty('hostileArchitecture', $this->hostileArchitecture($tags))
            ->setProperty('covered', $this->yesNo($this->tag($tags, 'covered')))
            ->setProperty('lit', $this->yesNo($this->tag($tags, 'lit')))
            ->setProperty('wasteBasket', $this->yesNo($this->tag($tags, 'bin')))
            ->setProperty('inscription', $this->tag($tags, 'inscription'))
            ->setProperty('memorial', 'bench' === $this->tag($tags, 'memorial') ? true : null)
            ->setProperty('level', is_numeric($level) ? (float) $level : null)
            ->setProperty('checkDate', $this->checkDate($tags))
            ->setProperty('dataProvider', $this->definition->publisher)
            ->setProperty('seeAlso', \sprintf('https://www.openstreetmap.org/%s/%d', $type, $id))
            ->setProperty('source', $this->definition->accessUrlWithQuery())
            ->geoProperty('location', $transformer->transformGeometry($this->definition->crs, $geometry))

            // Facility facts the model has no attribute for, or values that do
            // not fit the attribute it has, carried as the feed states them.
            //
            // material is carried here only when it is not one of the model's
            // terms, direction and level only when they are not a number or
            // compass point, and seats only when it is not a whole number. panoramax is the id of a street-level photo;
            // the model's image wants a URL, and the service's URL for a
            // photo is not established here. otherTags carries every tag this
            // mapping neither reads nor omits, under its OSM key, so a tag a
            // mapper adds later is not silently lost.
            ->additionalInformation([
                'material' => \in_array($material, self::MATERIALS, true) ? '' : $material,
                'direction' => null === $direction ? $this->tag($tags, 'direction') : '',
                'level' => is_numeric($level) ? '' : $level,
                'seats' => null === $this->seats($this->tag($tags, 'seats')) ? $this->tag($tags, 'seats') : '',
                'panoramax' => $this->tag($tags, 'panoramax'),
                'otherTags' => $this->otherTags($tags) ?: null,
            ]);
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

    /**
     * The model counts seats as a whole number of at least one.
     */
    private function seats(string $value): ?int
    {
        return ctype_digit($value) && (int) $value >= 1 ? (int) $value : null;
    }

    /**
     * direction is degrees clockwise from north, or one of the 16 compass
     * points, which the model requires as degrees.
     */
    private function direction(string $value): ?float
    {
        if (is_numeric($value) && (float) $value >= 0 && (float) $value < 360) {
            return (float) $value;
        }

        return self::COMPASS_POINTS[strtoupper($value)] ?? null;
    }

    /**
     * A "no" states that there are none, which the list cannot hold; any
     * value the model has no term for is still a hostile feature, so it maps
     * to "other".
     *
     * @param array<string, mixed> $tags
     *
     * @return list<string>
     */
    private function hostileArchitecture(array $tags): array
    {
        $features = array_map(
            static fn (string $value): string => match ($value) {
                'slanted' => 'slanted',
                'separated_seats', 'separate_seats' => 'separatedSeats',
                'spikes' => 'spikes',
                default => 'other',
            },
            array_filter(
                $this->list($this->tag($tags, 'hostile_architecture')),
                static fn (string $value): bool => 'no' !== $value
            )
        );

        return array_values(array_unique($features));
    }

    /**
     * check_date and survey:date both state when a mapper last saw the bench;
     * the more recent of the two is the one that holds.
     *
     * @param array<string, mixed> $tags
     */
    private function checkDate(array $tags): ?string
    {
        $dates = array_filter(
            [$this->tag($tags, 'check_date'), $this->tag($tags, 'survey:date')],
            static fn (string $date): bool => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
        );

        return [] === $dates ? null : max($dates);
    }

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
            if (\in_array($key, self::MAPPED_TAGS, true)
                || \in_array(explode(':', $key)[0], self::OMITTED_TAGS, true)) {
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
