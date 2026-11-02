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
     * Restates each site as bay records, and then groups all records closest
     * first, with at most one record from each data set in a group.
     */
    public function merge(array $records): array
    {
        $restater = new OnStreetParkingIntoSpots();
        $this->sites = [];
        $this->bayCounts = [];

        $spots = [];
        foreach ($records as $record) {
            if (OnStreetParkingIntoSpots::SITE_MODEL !== $record->model) {
                $spots[] = $record;
                continue;
            }

            $bays = $restater->restate($record);
            $this->sites[$record->id] = $record;
            $this->bayCounts[$record->id] = \count($bays);
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
        foreach ($bySite as $siteId => $bays) {
            $shape = (array) $this->siteShape($bays[0]);
            [$west, $south, $east, $north] = Shape::bounds($shape);
            $latitudeMargin = $this->radius / Distance::METRES_PER_DEGREE;
            $longitudeMargin = $latitudeMargin / max(0.01, cos(deg2rad(($south + $north) / 2)));

            foreach ($records as $other) {
                if ($other->sourceId === $bays[0]->sourceId
                    || $other->longitude < $west - $longitudeMargin || $other->longitude > $east + $longitudeMargin
                    || $other->latitude < $south - $latitudeMargin || $other->latitude > $north + $latitudeMargin) {
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
        // Collect the mapped bays that matched a bay of each site.
        $mappedBySite = [];
        foreach ($clusters as $cluster) {
            $mapped = array_filter($cluster->records, static fn (Record $record): bool => null === $record->restatedFrom);
            foreach ($cluster->records as $record) {
                if (null !== $record->restatedFrom) {
                    $mappedBySite[$record->restatedFrom] = [...$mappedBySite[$record->restatedFrom] ?? [], ...$mapped];
                }
            }
        }

        return array_map(function (Cluster $cluster) use ($mappedBySite): Cluster {
            if (array_any($cluster->records, static fn (Record $record): bool => null === $record->restatedFrom)) {
                return $cluster;
            }

            // A site of one bay gives the exact place of its bay.
            $generated = array_all($cluster->records, fn (Record $record): bool => ($this->bayCounts[(string) $record->restatedFrom] ?? 1) > 1);

            return new Cluster(array_map(
                fn (Record $record): Record => $this->placed($record, $mappedBySite[(string) $record->restatedFrom] ?? [], $generated),
                $cluster->records,
            ));
        }, $clusters);
    }

    /**
     * The bay record, moved to the mean point of the mapped bays and marked GENERATED if generated.
     *
     * @param list<Record> $mapped the mapped bays that matched other bays of its site
     */
    private function placed(Record $bay, array $mapped, bool $generated): Record
    {
        $longitude = $bay->longitude;
        $latitude = $bay->latitude;

        if ([] !== $mapped && null === $bay->geometry) {
            $mean = new Record($bay->id, $bay->sourceId, array_sum(array_column($mapped, 'longitude')) / \count($mapped), array_sum(array_column($mapped, 'latitude')) / \count($mapped));
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
}
