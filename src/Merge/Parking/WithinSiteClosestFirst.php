<?php

declare(strict_types=1);

namespace App\Merge\Parking;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use App\Conflation\Spatial\Distance;
use App\Conflation\Spatial\Pair;
use App\Conflation\Spatial\Shape;

/**
 * Merges parking bays and the OnStreetParking sites that hold them into
 * ParkingSpots.
 */
final class WithinSiteClosestFirst extends OneToOneClosestFirst
{
    /**
     * The attribute that marks a ParkingSpot that no mapped bay matched.
     */
    public const string GENERATED = ConflationFetcher::ADDITIONAL_INFORMATION.ConflationFetcher::KEY_SEPARATOR.'generated';

    /**
     * How far apart, in metres, two point sites of one data set may lie and
     * still be bays of one address.
     */
    private const float BAY_SPACING = 5.0;

    /**
     * The sites of the current run, by id.
     *
     * @var array<string, Record>
     */
    private array $sites = [];

    /**
     * The number of bay records of each site of the current run, by site id.
     *
     * @var array<string, int>
     */
    private array $bayCounts = [];

    /**
     * The positions of the bay records of each site of the current run that
     * has a line or an area, by site id. The bays lie spread along the site.
     *
     * @var array<string, list<array{float, float}>>
     */
    private array $positions = [];

    /**
     * Restates each site as bay records, and then groups all records closest
     * first, with at most one record from each data set in a group.
     */
    public function merge(array $records): array
    {
        $restater = new OnStreetParkingIntoSpots();
        $this->sites = [];
        $this->bayCounts = [];
        $this->positions = [];

        $oneBayEach = self::sitesOfOneBayEach($records);

        $spots = [];
        foreach ($records as $record) {
            if (OnStreetParkingIntoSpots::SITE_MODEL !== $record->model) {
                $spots[] = $record;
                continue;
            }

            $bays = $restater->restate(isset($oneBayEach[$record->id]) ? self::withOneBay($record) : $record);
            $this->sites[$record->id] = $record;
            $this->bayCounts[$record->id] = \count($bays);
            if (\count($bays) > 1 && null !== $this->siteShape($bays[0])) {
                $this->positions[$record->id] = array_map(static fn (Record $bay): array => [$bay->longitude, $bay->latitude], $bays);
            }
            array_push($spots, ...$bays);
        }

        return $this->placeUnmatched(parent::merge($spots));
    }

    /**
     * The pairs within the radius, measured from the site's shape where there is one.
     */
    protected function pairs(array $records): array
    {
        $pairs = [];
        // Measure the pairs near each other again, from the site's shape where there is one.
        foreach (parent::pairs($records) as $pair) {
            $pairs[$pair->a->id.' '.$pair->b->id] = new Pair($pair->a, $pair->b, $this->distance($pair->a, $pair->b));
        }

        // Add the pairs of a bay record and a record in or near the shape of its site.
        $bySite = [];
        foreach ($records as $record) {
            if (null !== $this->siteShape($record)) {
                $bySite[(string) $record->restatedFrom][] = $record;
            }
        }

        // Pair each bay of a site with each record of another source within the radius of the site's shape.
        // The box around the shape, widened by the radius, skips the records that are far away.
        foreach ($bySite as $bays) {
            [$west, $south, $east, $north] = Shape::bounds((array) $this->siteShape($bays[0]), $this->radius);

            foreach ($records as $other) {
                if ($other->sourceId === $bays[0]->sourceId
                    || $other->longitude < $west || $other->longitude > $east
                    || $other->latitude < $south || $other->latitude > $north) {
                    continue;
                }

                foreach ($bays as $bay) {
                    [$a, $b] = $bay->id < $other->id ? [$bay, $other] : [$other, $bay];
                    $key = $a->id.' '.$b->id;
                    if (isset($pairs[$key])) {
                        continue;
                    }

                    $distance = $this->distance($a, $b);
                    if ($distance <= $this->radius) {
                        $pairs[$key] = new Pair($a, $b, $distance);
                    }
                }
            }
        }

        return array_values($pairs);
    }

    /**
     * The pairs of mapped records first, closest first, and then the pairs
     * with a bay of a site. A bay of a site with a line or an area is 0 m from
     * every record inside its site. Thus, if it came first, it would join the
     * records in the order of their ids, not of their distances. A bay of a
     * site at a point also comes last. Otherwise it could take a mapped bay
     * before a bay of a site with an area at the same place.
     */
    protected function rank(Pair $pair): array
    {
        $site = null !== $pair->a->restatedFrom || null !== $pair->b->restatedFrom;

        return [$site ? 1 : 0, ...parent::rank($pair)];
    }

    /**
     * The distance between two records, measured from a site's shape where there is one.
     */
    protected function distance(Record $a, Record $b): float
    {
        $shapeA = $this->siteShape($a);
        $shapeB = $this->siteShape($b);
        if (null === $shapeA && null === $shapeB) {
            return parent::distance($a, $b);
        }

        // Measure from each record's point to the other's site shape, and keep the shorter.
        $distance = INF;
        if (null !== $shapeB) {
            $distance = min($distance, Shape::distance($a, $shapeB));
        }
        if (null !== $shapeA) {
            $distance = min($distance, Shape::distance($b, $shapeA));
        }

        return $distance;
    }

    /**
     * The area or line of the site of a bay record, or null.
     *
     * @return array<string, mixed>|null
     */
    private function siteShape(Record $record): ?array
    {
        $site = null === $record->restatedFrom ? null : ($this->sites[$record->restatedFrom] ?? null);
        $geometry = $site?->geometry;

        return null !== $geometry && 'Point' !== ($geometry['type'] ?? null) && 'MultiPoint' !== ($geometry['type'] ?? null) ? $geometry : null;
    }

    /**
     * The clusters, with the unmatched bay records placed and marked.
     *
     * @param list<Cluster> $clusters
     *
     * @return list<Cluster>
     */
    private function placeUnmatched(array $clusters): array
    {
        // Collect the mapped bays that matched a bay of each site, and the
        // point of each match: the mean point of its mapped bays.
        $mappedBySite = [];
        $takenBySite = [];
        foreach ($clusters as $cluster) {
            $mapped = array_values(array_filter($cluster->records, static fn (Record $record): bool => null === $record->restatedFrom));
            if ([] === $mapped) {
                continue;
            }

            foreach ($cluster->records as $record) {
                if (null !== $record->restatedFrom) {
                    $mappedBySite[$record->restatedFrom] = [...$mappedBySite[$record->restatedFrom] ?? [], ...$mapped];
                    $takenBySite[$record->restatedFrom][] = self::mean($record->id, $mapped);
                }
            }
        }

        // Find the positions along each site that no match took.
        $free = [];
        foreach ($this->positions as $siteId => $positions) {
            $free[$siteId] = self::freePositions($positions, $takenBySite[$siteId] ?? []);
        }

        $placed = [];
        foreach ($clusters as $cluster) {
            if (array_any($cluster->records, static fn (Record $record): bool => null === $record->restatedFrom)) {
                $placed[] = $cluster;
                continue;
            }

            // A site of one bay gives the exact place of its bay.
            $generated = array_all($cluster->records, fn (Record $record): bool => ($this->bayCounts[(string) $record->restatedFrom] ?? 1) > 1);

            $records = [];
            foreach ($cluster->records as $record) {
                $siteId = (string) $record->restatedFrom;
                $position = isset($free[$siteId]) ? array_shift($free[$siteId]) : null;
                $records[] = $this->placed($record, $mappedBySite[$siteId] ?? [], $position, $generated);
            }
            $placed[] = new Cluster($records);
        }

        return $placed;
    }

    /**
     * The bay record, placed and marked GENERATED if generated.
     *
     * A bay of a site with a line or an area moves to a free position along
     * the site. A bay of a site at a point moves to the mean point of the
     * mapped bays, if that point lies within the radius of the site.
     *
     * @param list<Record>             $mapped   the mapped bays that matched other bays of its site
     * @param array{float, float}|null $position a free position along the site, if the site has a line or an area
     */
    private function placed(Record $bay, array $mapped, ?array $position, bool $generated): Record
    {
        $longitude = $bay->longitude;
        $latitude = $bay->latitude;

        if (null !== $position) {
            [$longitude, $latitude] = $position;
        } elseif ([] !== $mapped && null === $bay->geometry && !isset($this->positions[(string) $bay->restatedFrom])) {
            $mean = self::mean($bay->id, $mapped);
            $site = $this->sites[(string) $bay->restatedFrom];
            if (Shape::distance($mean, $site->location()) <= $this->radius) {
                $longitude = $mean->longitude;
                $latitude = $mean->latitude;
            }
        }

        $attributes = $generated ? [...$bay->attributes, self::GENERATED => true] : $bay->attributes;
        ksort($attributes, \SORT_STRING);

        return new Record($bay->id, $bay->sourceId, $longitude, $latitude, $bay->model, $attributes, $bay->geometry, $bay->restatedFrom);
    }

    /**
     * The positions that no match took, in their order along the site. Each
     * match takes the free position nearest to it, closest first.
     *
     * @param list<array{float, float}> $positions
     * @param list<Record>              $taken     the point of each match
     *
     * @return list<array{float, float}>
     */
    private static function freePositions(array $positions, array $taken): array
    {
        $pairs = [];
        foreach ($taken as $t => $match) {
            foreach ($positions as $p => [$longitude, $latitude]) {
                $pairs[] = [Distance::between($match, new Record('', '', $longitude, $latitude)), $t, $p];
            }
        }
        sort($pairs);

        $usedMatches = [];
        $usedPositions = [];
        foreach ($pairs as [, $t, $p]) {
            if (!isset($usedMatches[$t]) && !isset($usedPositions[$p])) {
                $usedMatches[$t] = true;
                $usedPositions[$p] = true;
            }
        }

        return array_values(array_diff_key($positions, $usedPositions));
    }

    /**
     * A record at the mean point of the records.
     *
     * @param non-empty-list<Record> $records
     */
    private static function mean(string $id, array $records): Record
    {
        return new Record($id, '', array_sum(array_column($records, 'longitude')) / \count($records), array_sum(array_column($records, 'latitude')) / \count($records));
    }

    /**
     * The point sites that each state one bay of an address, by id.
     *
     * A register can state each bay of an address as a site of its own, with
     * the number of bays at the whole address. For example, two sites 3 m
     * apart that each state 2 bays are the 2 bays. Point sites of one data
     * set with the same number N of bays, each within BAY_SPACING of the
     * next, are such bays if there are 2 to N of them.
     *
     * @param list<Record> $records
     *
     * @return array<string, true>
     */
    private static function sitesOfOneBayEach(array $records): array
    {
        $sites = array_values(array_filter(
            $records,
            static fn (Record $record): bool => OnStreetParkingIntoSpots::SITE_MODEL === $record->model
                && null === $record->geometry
                && \is_int($record->attributes['totalSpotNumber'] ?? null)
                && $record->attributes['totalSpotNumber'] > 1,
        ));

        // Link the sites of one data set with the same number of bays that lie near each other.
        $root = array_keys($sites);
        $find = static function (int $i) use (&$root): int {
            while ($root[$i] !== $i) {
                $i = $root[$i];
            }

            return $i;
        };
        foreach ($sites as $i => $a) {
            foreach (\array_slice($sites, $i + 1, null, true) as $j => $b) {
                if ($a->sourceId === $b->sourceId
                    && $a->attributes['totalSpotNumber'] === $b->attributes['totalSpotNumber']
                    && Distance::between($a, $b) <= self::BAY_SPACING) {
                    $root[$find($j)] = $find($i);
                }
            }
        }

        $groups = [];
        foreach ($sites as $i => $site) {
            $groups[$find($i)][] = $site;
        }

        $oneBayEach = [];
        foreach ($groups as $group) {
            $count = \count($group);
            if ($count >= 2 && $count <= $group[0]->attributes['totalSpotNumber']) {
                foreach ($group as $site) {
                    $oneBayEach[$site->id] = true;
                }
            }
        }

        return $oneBayEach;
    }

    /**
     * The site, as a site of one bay.
     */
    private static function withOneBay(Record $site): Record
    {
        return new Record($site->id, $site->sourceId, $site->longitude, $site->latitude, $site->model, ['totalSpotNumber' => 1] + $site->attributes, $site->geometry, $site->restatedFrom);
    }
}
