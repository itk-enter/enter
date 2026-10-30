<?php

declare(strict_types=1);

namespace App\Conflation\Spatial;

use App\Conflation\Data\Record;

/**
 * Finds the pairs of records within a radius of each other. It does not
 * compare every record with every other record.
 *
 * The index puts the records into cells that are at least the radius wide.
 * Thus a record within the radius of another record is in the same cell or
 * in one of the eight cells around it. Only the cells that hold records
 * exist, so the index does not lay out a grid over the area in advance. The
 * cost increases with the number of records, not with its square.
 *
 * To compare every record with every other record costs the square of the
 * number of records. This is too slow for large data sets: five data sets of
 * 50,000 records each need approximately 3 × 10¹⁰ comparisons. The grid
 * compares each record only with the few records near it.
 *
 * Neither geoPHP nor proj4php has a spatial index. The index sizes the cells
 * in degrees, not in a projected CRS. Thus the records need no transform, and
 * the grid works at all locations, not only in the zone of one projection.
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

        // Size the cells for the record furthest from the equator. A degree
        // of longitude becomes shorter towards the poles. If the cells fit
        // where it is shortest, every cell is at least the radius wide across
        // the whole set.
        $maxLatitude = array_reduce($records, static fn (float $max, Record $record): float => max($max, abs($record->latitude)), 0.0);
        $this->cellLatitude = $radius / Distance::METRES_PER_DEGREE;
        $this->cellLongitude = $radius / (Distance::METRES_PER_DEGREE * max(cos(deg2rad($maxLatitude)), 1e-6));

        // Put every record in the cell its point falls in.
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

        // Pair every record with the records near it.
        foreach ($this->records as $index => $record) {
            [$column, $row] = $this->cell($record);

            // Look in its own cell and the eight around it.
            for ($dColumn = -1; $dColumn <= 1; ++$dColumn) {
                for ($dRow = -1; $dRow <= 1; ++$dRow) {
                    // Keep each record there from another data set within the radius.
                    foreach ($this->cells[$column + $dColumn][$row + $dRow] ?? [] as $other) {
                        // Records of one data set describe different things,
                        // and the index reports a pair from its lower-indexed
                        // record.
                        if ($other <= $index || $this->records[$other]->sourceId === $record->sourceId) {
                            continue;
                        }

                        $distance = Distance::between($record, $this->records[$other]);
                        if ($distance <= $this->radius) {
                            // Order the pair by id, so that it is the same in
                            // any order of the records.
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
