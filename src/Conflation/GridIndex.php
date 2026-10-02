<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * Finds the pairs of records within a radius of each other without
 * comparing every record with every other.
 *
 * Records are bucketed into cells at least the radius wide, so any record
 * within the radius of another lies in the same cell or one of the eight
 * around it. Only occupied cells exist, so nothing is laid out over the
 * area in advance, and the cost grows with the number of records rather
 * than with its square.
 *
 * Neither geoPHP nor proj4php has a spatial index. Cells are sized in
 * degrees rather than in a projected CRS, so records need no transform and
 * the grid works wherever they are, not only within one projection's zone.
 */
final class GridIndex
{
    /** @var array<int, array<int, list<int>>> record indexes by cell */
    private array $cells = [];

    private readonly float $cellLatitude;

    private readonly float $cellLongitude;

    /**
     * @param list<Record> $records
     */
    public function __construct(
        private readonly array $records,
        private readonly float $radius,
    ) {
        if ($radius <= 0) {
            throw new \InvalidArgumentException(\sprintf('The radius must be positive, got %F.', $radius));
        }

        // A degree of longitude shrinks towards the poles. Sizing the cells
        // where it is shortest keeps every cell at least the radius wide
        // across the whole set.
        $maxLatitude = array_reduce($records, static fn (float $max, Record $record): float => max($max, abs($record->latitude)), 0.0);
        $this->cellLatitude = $radius / Distance::METRES_PER_DEGREE;
        $this->cellLongitude = $radius / (Distance::METRES_PER_DEGREE * max(cos(deg2rad($maxLatitude)), 1e-6));

        // Every record, into the cell its point falls in.
        foreach ($records as $index => $record) {
            [$column, $row] = $this->cell($record);
            $this->cells[$column][$row][] = $index;
        }
    }

    /**
     * Every pair of records from different data sets within the radius,
     * each pair once.
     *
     * @return list<Pair>
     */
    public function pairs(): array
    {
        $pairs = [];

        // Every record, as the first of a pair.
        foreach ($this->records as $index => $record) {
            [$column, $row] = $this->cell($record);

            // Its own cell and the eight around it.
            for ($dColumn = -1; $dColumn <= 1; ++$dColumn) {
                for ($dRow = -1; $dRow <= 1; ++$dRow) {
                    // Every record in that cell, as the second of the pair.
                    foreach ($this->cells[$column + $dColumn][$row + $dRow] ?? [] as $other) {
                        // Records of one data set describe different things,
                        // and a pair is reported from its lower-indexed record.
                        if ($other <= $index || $this->records[$other]->sourceId === $record->sourceId) {
                            continue;
                        }

                        $distance = Distance::between($record, $this->records[$other]);
                        if ($distance <= $this->radius) {
                            // Ordered by id, so a pair reads the same whatever
                            // order the records came in.
                            [$a, $b] = $record->id < $this->records[$other]->id
                                ? [$record, $this->records[$other]]
                                : [$this->records[$other], $record];
                            $pairs[] = new Pair($a, $b, $distance);
                        }
                    }
                }
            }
        }

        return $pairs;
    }

    /**
     * @return array{int, int}
     */
    private function cell(Record $record): array
    {
        return [
            (int) floor($record->longitude / $this->cellLongitude),
            (int) floor($record->latitude / $this->cellLatitude),
        ];
    }
}
