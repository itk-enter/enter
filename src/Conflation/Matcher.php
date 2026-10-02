<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * Groups records from several data sets into clusters, one per real-world
 * thing.
 *
 * Distinct things of one kind can stand closer together than two records of
 * the same thing from different data sets, so no radius alone separates
 * them. Matching is therefore one-to-one and closest first: the pairs within
 * the radius are taken in order of distance, and a pair joins two clusters
 * only while
 *
 * - the result holds at most one record from each data set, and
 * - every record in it is within the radius of every other.
 *
 * The first rule keeps two neighbouring things from both claiming the same
 * record; the second keeps a chain of matches from drifting further than the
 * radius allows. A record that matches nothing is a cluster of one, so the
 * clusters cover every input record.
 */
final class Matcher
{
    /**
     * @param list<Record> $records
     *
     * @return list<Cluster> ordered by their first record's id
     */
    public function match(array $records, float $radius): array
    {
        $this->assertUniqueIds($records);

        $pairs = new GridIndex($records, $radius)->pairs();

        // Order the pairs closest first, ties broken by id so a run is
        // repeatable.
        usort($pairs, static fn (Pair $x, Pair $y): int => [$x->distance, $x->a->id, $x->b->id] <=> [$y->distance, $y->a->id, $y->b->id]);

        /** @var array<string, string> $root cluster root by record id */
        $root = [];
        /** @var array<string, list<Record>> $members cluster members by root */
        $members = [];
        // Start every record off as a cluster of its own.
        foreach ($records as $record) {
            $root[$record->id] = $record->id;
            $members[$record->id] = [$record];
        }

        // Join clusters pair by pair, closest first.
        foreach ($pairs as $pair) {
            $rootA = $root[$pair->a->id];
            $rootB = $root[$pair->b->id];

            if ($rootA === $rootB || !$this->canJoin($members[$rootA], $members[$rootB], $radius)) {
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
        // handful of records.
        foreach ($a as $x) {
            foreach ($b as $y) {
                if (Distance::between($x, $y) > $radius) {
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
