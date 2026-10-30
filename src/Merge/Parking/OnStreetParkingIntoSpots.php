<?php

declare(strict_types=1);

namespace App\Merge\Parking;

use App\Conflation\Data\Record;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\Spatial\Shape;

/**
 * Restates an OnStreetParking record as one ParkingSpot record for each of
 * its bays.
 */
final class OnStreetParkingIntoSpots
{
    public const string SITE_MODEL = 'OnStreetParking';

    public const string SPOT_MODEL = 'ParkingSpot';

    private const string FEE = ConflationFetcher::ADDITIONAL_INFORMATION.ConflationFetcher::KEY_SEPARATOR.'fee';

    /**
     * The bays of a site, as ParkingSpot records.
     *
     * The site gives one record for each bay in totalSpotNumber, or one
     * record if it states no number. If the site is a line or an area, the
     * records lie spread along it, as Shape::spread() gives them. Otherwise
     * each record lies at the site's point. Each record has the data set of
     * the site and names the site in restatedFrom. A site of one bay also
     * gives its full geometry to that bay.
     *
     * @return list<Record>
     */
    public function restate(Record $site): array
    {
        $count = $site->attributes['totalSpotNumber'] ?? null;
        $count = \is_int($count) && $count > 0 ? $count : 1;

        $attributes = self::attributes($site);
        $geometry = 1 === $count ? $site->geometry : null;
        $positions = $count > 1 && \in_array($site->geometry['type'] ?? null, ['LineString', 'Polygon', 'MultiPolygon'], true)
            ? Shape::spread((array) $site->geometry, $count)
            : [];

        $spots = [];
        for ($number = 1; $number <= $count; ++$number) {
            [$longitude, $latitude] = $positions[$number - 1] ?? [$site->longitude, $site->latitude];
            $spots[] = new Record(self::spotId($site->id, $number), $site->sourceId, $longitude, $latitude, self::SPOT_MODEL, $attributes, $geometry, $site->id);
        }

        return $spots;
    }

    /**
     * The attributes of a site, as a ParkingSpot states them.
     *
     * The name, the description and the additional information are kept.
     * The fee in the category becomes the fee in the additional
     * information. The number of bays and the parking mode are left out.
     *
     * @return array<string, mixed>
     */
    private static function attributes(Record $site): array
    {
        $attributes = array_filter(
            $site->attributes,
            static fn (string $attribute): bool => \in_array($attribute, ['name', 'description'], true)
                || str_starts_with($attribute, ConflationFetcher::ADDITIONAL_INFORMATION.ConflationFetcher::KEY_SEPARATOR),
            \ARRAY_FILTER_USE_KEY,
        );
        $attributes['status'] = 'unknown';
        $attributes['category'] = ['onStreet'];

        $category = $site->attributes['category'] ?? [];
        if (\is_array($category) && \in_array('feeCharged', $category, true)) {
            $attributes[self::FEE] ??= 'yes';
        } elseif (\is_array($category) && \in_array('free', $category, true)) {
            $attributes[self::FEE] ??= 'no';
        }
        ksort($attributes, \SORT_STRING);

        return $attributes;
    }

    /**
     * The id of a site's bay: urn:ngsi-ld:ParkingSpot:<the site's local id>-bay-<number>.
     */
    private static function spotId(string $siteId, int $number): string
    {
        $localId = 1 === preg_match('/^urn:ngsi-ld:[^:]+:(.+)$/', $siteId, $match) ? $match[1] : $siteId;

        return \sprintf('urn:ngsi-ld:%s:%s-bay-%d', self::SPOT_MODEL, $localId, $number);
    }
}
