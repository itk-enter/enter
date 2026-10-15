<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * One input record as matching sees it: which entity, from which data set,
 * and the point it is compared by.
 *
 * Matching works on a single representative point, so an area or a multi
 * point is reduced to one before it gets here.
 */
final readonly class Record
{
    public function __construct(
        public string $id,
        public string $sourceId,
        public float $longitude,
        public float $latitude,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(\sprintf('Record %s is not a WGS84 position: (%F, %F).', $id, $longitude, $latitude));
        }
    }
}
