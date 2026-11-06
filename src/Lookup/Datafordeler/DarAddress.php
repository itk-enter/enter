<?php

declare(strict_types=1);

namespace App\Lookup\Datafordeler;

use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Geo\Wgs84Transformer;
use Psr\Clock\ClockInterface;

/**
 * Gives the nearest address of a point from Danmarks Adresseregister (DAR).
 *
 * The lookup searches in steps of 20, 50 and 80 metres around the point.
 * In each step, it gets the access points of DAR in a square around the
 * point, and keeps the access points in the circle of the step. Then it gets
 * the house numbers of these access points. It ignores a house number that
 * is not in use. The house number with the nearest access point gives the
 * address. If no step finds an address, the lookup has no answer.
 *
 * Each step makes two requests. The positions in DAR are in EPSG:25832. A
 * step gets 100 access points at most, because a filter with "in" accepts
 * 100 values at most.
 *
 * @see https://confluence.kds.dk/pages/viewpage.action?pageId=10616849
 */
#[AsLocationLookup(
    id: 'dar-address',
    attributes: ['address'],
    recheckEmptyAfter: new \DateInterval('P30D'),
    refreshAfter: new \DateInterval('P90D'),
    transport: 'datafordeler',
)]
final readonly class DarAddress implements LocationLookupInterface
{
    /**
     * The radius of each step, in metres.
     */
    public const array RADII = [20, 50, 80];

    /**
     * The status of a house number in use ("gældende").
     */
    public const string IN_USE = '3';

    private const string CRS = 'EPSG:25832';

    private const string ACCESS_POINTS = <<<'GRAPHQL'
        query ($area: String!, $now: DafDateTime!) {
          DAR_Adressepunkt(first: 100, virkningstid: $now, where: { position: { within: { wkt: $area, crs: 25832 } } }) {
            nodes { id_lokalId position { wkt } }
          }
        }
        GRAPHQL;

    private const string HOUSE_NUMBERS = <<<'GRAPHQL'
        query ($accessPoints: [String!], $now: DafDateTime!) {
          DAR_Husnummer(first: 100, virkningstid: $now, where: { adgangspunkt: { in: $accessPoints } }) {
            nodes {
              adgangspunkt
              status
              husnummertekst
              husnummerHoererTilNavngivenVej { vejnavn }
              husnummerHoererTilIPostnummer { postnr navn }
              husnummerHoererTilSupplerendeBynavn { navn }
            }
          }
        }
        GRAPHQL;

    public function __construct(
        private DatafordelerClient $client,
        private Wgs84Transformer $transformer,
        private ClockInterface $clock,
    ) {
    }

    public function lookup(float $longitude, float $latitude): ?array
    {
        [$x, $y] = $this->transformer->fromWgs84(self::CRS, $longitude, $latitude);
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format(DatafordelerClient::TIME_FORMAT);

        foreach (self::RADII as $radius) {
            $distances = $this->accessPoints($x, $y, $radius, $now);
            if ([] === $distances) {
                continue;
            }

            $address = $this->nearestAddress($distances, $now);
            if (null !== $address) {
                return ['address' => $address];
            }
        }

        return null;
    }

    /**
     * Gets the access points in a circle around a point.
     *
     * @return array<string, float> the distance in metres by the id of the access point
     */
    private function accessPoints(float $x, float $y, int $radius, string $now): array
    {
        $area = \sprintf(
            'POLYGON((%1$F %2$F, %3$F %2$F, %3$F %4$F, %1$F %4$F, %1$F %2$F))',
            $x - $radius, $y - $radius, $x + $radius, $y + $radius,
        );
        $data = $this->client->query(self::ACCESS_POINTS, ['area' => $area, 'now' => $now]);

        $distances = [];
        foreach (self::nodes($data, 'DAR_Adressepunkt') as $node) {
            $id = $node['id_lokalId'] ?? null;
            $wkt = $node['position']['wkt'] ?? null;
            if (!\is_string($id) || !\is_string($wkt) || 1 !== preg_match('/^POINT\s*\(\s*(\S+)\s+(\S+)\s*\)$/i', $wkt, $match)) {
                continue;
            }

            $distance = hypot((float) $match[1] - $x, (float) $match[2] - $y);
            if ($distance <= $radius) {
                $distances[$id] = $distance;
            }
        }
        asort($distances);

        return $distances;
    }

    /**
     * Gets the address of the house number with the nearest access point.
     *
     * @param array<string, float> $distances the distance in metres by the id of the access point
     *
     * @return array<string, string>|null a postal address, or null if no house number is in use
     */
    private function nearestAddress(array $distances, string $now): ?array
    {
        $data = $this->client->query(self::HOUSE_NUMBERS, ['accessPoints' => array_keys($distances), 'now' => $now]);

        $nearest = null;
        $nearestDistance = INF;
        foreach (self::nodes($data, 'DAR_Husnummer') as $node) {
            $distance = $distances[$node['adgangspunkt'] ?? ''] ?? null;
            $street = trim((string) ($node['husnummerHoererTilNavngivenVej']['vejnavn'] ?? ''));
            if (null === $distance || self::IN_USE !== ($node['status'] ?? null) || '' === $street || $distance >= $nearestDistance) {
                continue;
            }

            $nearest = $node;
            $nearestDistance = $distance;
        }

        return null === $nearest ? null : self::postalAddress($nearest);
    }

    /**
     * Makes the postal address of a house number, as the Address of Smart
     * Data Models. The supplementary town name ("supplerende bynavn") goes in
     * "district".
     *
     * @param array<string, mixed> $houseNumber
     *
     * @return array<string, string>
     */
    private static function postalAddress(array $houseNumber): array
    {
        $street = trim((string) ($houseNumber['husnummerHoererTilNavngivenVej']['vejnavn'] ?? ''));
        $number = trim((string) ($houseNumber['husnummertekst'] ?? ''));

        return array_filter([
            'streetAddress' => trim(\sprintf('%s %s', $street, $number)),
            'postalCode' => trim((string) ($houseNumber['husnummerHoererTilIPostnummer']['postnr'] ?? '')),
            'addressLocality' => trim((string) ($houseNumber['husnummerHoererTilIPostnummer']['navn'] ?? '')),
            'district' => trim((string) ($houseNumber['husnummerHoererTilSupplerendeBynavn']['navn'] ?? '')),
            'addressCountry' => 'DK',
        ], static fn (string $value): bool => '' !== $value);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private static function nodes(array $data, string $entity): array
    {
        $nodes = $data[$entity]['nodes'] ?? [];

        return \is_array($nodes) ? array_values(array_filter($nodes, \is_array(...))) : [];
    }
}
