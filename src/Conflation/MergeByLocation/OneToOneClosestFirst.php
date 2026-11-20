<?php

declare(strict_types=1);

namespace App\Conflation\MergeByLocation;

use App\Conflation\Data\Cluster;
use App\Conflation\Data\Record;
use App\Conflation\Spatial\Distance;
use App\Conflation\Spatial\GridIndex;
use App\Conflation\Spatial\Pair;

/**
 * The default merge by location: groups records from several data sets into
 * clusters, one per real-world thing.
 *
 * Two different things of one kind can be nearer to each other than two
 * records of one thing from different data sets. Thus a radius alone cannot
 * separate them. The match is one-to-one and closest first. The class takes
 * the pairs within the radius in order of distance. A pair joins two
 * clusters only if
 *
 * - the result holds at most one record from each data set, and
 * - every record in it is within the radius of every other.
 *
 * The first rule makes sure that two neighbouring things do not both take
 * the same record. The second rule makes sure that a chain of matches does
 * not go further than the radius. A record that matches nothing is a cluster
 * of one, so the clusters cover every input record.
 *
 * The class compares every record by its representative point, so it
 * matches points and areas in the same way. For an area, it uses the
 * centroid (see Record::fromGeometry()). The class is open, so a
 * customization can extend it. For example, a subclass can match a point
 * against the area that it is in, and leave the rest to this rule.
 */
class OneToOneClosestFirst extends AbstractMergeByLocation
{
    /**
     * @return list<Cluster> ordered by their first record's id
     */
    public function merge(array $records): array
    {
        $this->assertUniqueIds($records);

        $pairs = $this->pairs($records);

        // Order the pairs by their rank: by default closest first.
        usort($pairs, fn (Pair $x, Pair $y): int => $this->rank($x) <=> $this->rank($y));

        /** @var array<string, string> $root cluster root by record id */
        $root = [];
        /** @var array<string, list<Record>> $members cluster members by root */
        $members = [];
        // At the start, make every record a cluster of its own.
        foreach ($records as $record) {
            $root[$record->id] = $record->id;
            $members[$record->id] = [$record];
        }

        // Join clusters pair by pair, closest first.
        foreach ($pairs as $pair) {
            $rootA = $root[$pair->a->id];
            $rootB = $root[$pair->b->id];

            if ($rootA === $rootB || !$this->canJoin($members[$rootA], $members[$rootB], $this->radius)) {
                continue;
            }

            // Move every record of cluster B into cluster A.
            foreach ($members[$rootB] as $record) {
                $root[$record->id] = $rootA;
            }
            $members[$rootA] = [...$members[$rootA], ...$members[$rootB]];
            unset($members[$rootB]);
        }

        // Turn every group of members into a cluster, its records ordered by
        // data set, then id.
        $clusters = array_map(static function (array $records): Cluster {
            usort($records, static fn (Record $x, Record $y): int => [$x->sourceId, $x->id] <=> [$y->sourceId, $y->id]);

            return new Cluster($records);
        }, array_values($members));

        // Order the clusters by their first record's id, so a run is repeatable.
        usort($clusters, static fn (Cluster $x, Cluster $y): int => $x->records[0]->id <=> $y->records[0]->id);

        return $clusters;
    }

    /**
     * The pairs of records from different data sets within the radius of
     * each other.
     *
     * @param list<Record> $records
     *
     * @return list<Pair>
     */
    protected function pairs(array $records): array
    {
        return new GridIndex($records, $this->radius)->pairs();
    }

    /**
     * The distance, in metres, between two records.
     */
    protected function distance(Record $a, Record $b): float
    {
        return Distance::between($a, $b);
    }

    /**
     * The rank of a pair. merge() joins the pairs in the order of their rank,
     * the lowest first, and compares the ranks as arrays.
     *
     * The default rank is the distance of the pair, then the ids of its
     * records. The ids break ties, so that a run is repeatable. A subclass can
     * put a value before the distance to take some pairs before others.
     *
     * @return list<float|int|string>
     */
    protected function rank(Pair $pair): array
    {
        return [$pair->distance, $pair->a->id, $pair->b->id];
    }

    /**
     * @param list<Record> $a
     * @param list<Record> $b
     */
    private function canJoin(array $a, array $b, float $radius): bool
    {
        // Collect the data sets A already holds.
        $sourcesA = array_map(static fn (Record $record): string => $record->sourceId, $a);
        // Refuse if B has a record from a data set A already holds.
        foreach ($b as $record) {
            if (\in_array($record->sourceId, $sourcesA, true)) {
                return false;
            }
        }

        // Refuse if any record of A lies beyond the radius of any record of B.
        // Clusters hold one record per data set, so this compares only a
        // small number of records.
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($this->distance($x, $y) > $radius) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param list<Record> $records
     */
    private function assertUniqueIds(array $records): void
    {
        $seen = [];
        // Reject an id that occurs twice.
        foreach ($records as $record) {
            if (isset($seen[$record->id])) {
                throw new \InvalidArgumentException(\sprintf('Record id %s occurs more than once.', $record->id));
            }
            $seen[$record->id] = true;
        }
    }
}
