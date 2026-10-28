<?php

declare(strict_types=1);

namespace App\Conflation\Data;

use App\GeometryHelper;

/**
 * One input record as conflation sees it: which entity, from which data set,
 * the point that the merge compares it by, and what it states.
 *
 * The match uses one representative point. Thus fromGeometry() reduces an
 * area or a multi point to one point. The record also keeps the full
 * geometry for the steps that compare shapes.
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
     * A record located by a GeoJSON geometry in WGS84, compared by its
     * representative point: a point's own position, or the centroid of any
     * other geometry.
     *
     * The centroid comes from GeometryHelper::getCentroid(). Without the GEOS
     * extension, geoPHP calculates the centroid of a small area with less
     * precision. Thus only points match reliably.
     *
     * @param array<string, mixed> $geometry
     * @param array<string, mixed> $attributes
     */
    public static function fromGeometry(string $id, string $sourceId, array $geometry, string $model = '', array $attributes = []): self
    {
        if ('Point' === ($geometry['type'] ?? null)) {
            $coordinates = $geometry['coordinates'] ?? null;
            if (!\is_array($coordinates) || !is_numeric($coordinates[0] ?? null) || !is_numeric($coordinates[1] ?? null)) {
                throw new \InvalidArgumentException(\sprintf('Record %s has a point without coordinates.', $id));
            }

            return new self($id, $sourceId, (float) $coordinates[0], (float) $coordinates[1], $model, $attributes);
        }

        [$longitude, $latitude] = new GeometryHelper()->getCentroid($geometry)->toArray();

        return new self($id, $sourceId, (float) $longitude, (float) $latitude, $model, $attributes, $geometry);
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
