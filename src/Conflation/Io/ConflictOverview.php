<?php

declare(strict_types=1);

namespace App\Conflation\Io;

/**
 * The conflicts of one merged data set, for its page on /sources/{id}.
 */
final readonly class ConflictOverview
{
    /**
     * @param list<ConflictedEntity> $entities      the merged entities with conflicts, the most conflicts first
     * @param array<string, string>  $dataSetTitles the title of each data set that the merge reads, by id
     */
    public function __construct(
        public MergedDataSet $dataSet,
        public array $entities,
        public array $dataSetTitles,
    ) {
    }

    /**
     * Gets the number of entities with a conflict on each attribute, the
     * highest number first.
     *
     * @return array<string, int>
     */
    public function attributes(): array
    {
        $counts = [];
        foreach ($this->entities as $entity) {
            foreach (array_keys($entity->conflicts) as $attribute) {
                $counts[$attribute] = ($counts[$attribute] ?? 0) + 1;
            }
        }

        return self::sorted($counts);
    }

    /**
     * Gets the number of conflicts that each data set states a value in,
     * the highest number first.
     *
     * @return array<string, int>
     */
    public function dataSets(): array
    {
        $counts = [];
        foreach ($this->entities as $entity) {
            foreach ($entity->conflicts as $values) {
                foreach (array_unique(array_column($values, 'dataSet')) as $dataSet) {
                    $counts[$dataSet] = ($counts[$dataSet] ?? 0) + 1;
                }
            }
        }

        return self::sorted($counts);
    }

    /**
     * Gets the title of a data set, or its id if the merge does not read it.
     */
    public function dataSetTitle(string $id): string
    {
        return $this->dataSetTitles[$id] ?? $id;
    }

    /**
     * Gets the entities with a geometry as a GeoJSON FeatureCollection for
     * the map. Each feature has the title and the attributes in conflict of
     * its entity, and its number in the list of entities.
     *
     * @return array{type: string, features: list<array<string, mixed>>}
     */
    public function featureCollection(): array
    {
        $features = [];
        foreach ($this->entities as $number => $entity) {
            if (null !== $entity->geometry) {
                $features[] = [
                    'type' => 'Feature',
                    'id' => $entity->id,
                    'geometry' => $entity->geometry,
                    'properties' => [
                        'title' => $entity->title(),
                        'attributes' => array_keys($entity->conflicts),
                        'number' => $number,
                    ],
                ];
            }
        }

        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    /**
     * @param array<string, int> $counts
     *
     * @return array<string, int>
     */
    private static function sorted(array $counts): array
    {
        uksort($counts, static fn (string $a, string $b): int => [$counts[$b], $a] <=> [$counts[$a], $b]);

        return $counts;
    }
}
