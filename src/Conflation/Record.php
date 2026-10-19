<?php

declare(strict_types=1);

namespace App\Conflation;

/**
 * One input record as conflation sees it: which entity, from which data set,
 * the point it is compared by, and what it states.
 *
 * Matching works on a single representative point, so an area or a multi
 * point is reduced to one before it gets here. The full geometry is kept
 * beside it for steps that compare shapes.
 */
final readonly class Record
{
    /**
     * @param array<string, mixed>      $attributes the values the record states, by attribute name; a value it does
     *                                              not state is absent, never null
     * @param array<string, mixed>|null $geometry   the full GeoJSON geometry, where it is more than the point
     */
    public function __construct(
        public string $id,
        public string $sourceId,
        public float $longitude,
        public float $latitude,
        public string $model = '',
        public array $attributes = [],
        public ?array $geometry = null,
    ) {
        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(\sprintf('Record %s is not a WGS84 position: (%F, %F).', $id, $longitude, $latitude));
        }
    }

    /**
     * The record's location as GeoJSON: its full geometry, or its point.
     *
     * @return array<string, mixed>
     */
    public function location(): array
    {
        return $this->geometry ?? ['type' => 'Point', 'coordinates' => [$this->longitude, $this->latitude]];
    }
}
