<?php

declare(strict_types=1);

namespace App\Conflation\Io;

/**
 * A merged entity with the attributes that its merge could not settle.
 *
 * For each of these attributes, the merge leaves the attribute out of the
 * entity, and keeps the values of the data sets in
 * additionalInformation.conflicts.
 */
final readonly class ConflictedEntity
{
    /**
     * @param string|null                                                               $name      the name of the entity, or null if it has none
     * @param array<string, mixed>|null                                                 $geometry  the GeoJSON geometry of the entity, or null if it has none
     * @param array<string, list<array{value: mixed, dataSet: string, entity: string}>> $conflicts the value of each data set, by attribute
     */
    public function __construct(
        public string $id,
        public ?string $name,
        public ?array $geometry,
        public array $conflicts,
    ) {
    }

    /**
     * Makes the entity from a GeoJSON feature of
     * NgsiLdBroker::conflictedFeatures(), or gives null if the feature has
     * no valid conflict.
     *
     * @param array<string, mixed> $feature
     */
    public static function fromFeature(array $feature): ?self
    {
        $id = $feature['id'] ?? null;
        $properties = \is_array($feature['properties'] ?? null) ? $feature['properties'] : [];
        $information = \is_array($properties[ConflationFetcher::ADDITIONAL_INFORMATION] ?? null) ? $properties[ConflationFetcher::ADDITIONAL_INFORMATION] : [];
        $stated = \is_array($information[MergedEntityFactory::CONFLICTS] ?? null) ? $information[MergedEntityFactory::CONFLICTS] : [];

        $conflicts = [];
        foreach ($stated as $attribute => $values) {
            $values = array_values(array_filter(\is_array($values) ? $values : [], self::isStatedValue(...)));
            if (\is_string($attribute) && \count($values) > 1) {
                $conflicts[$attribute] = $values;
            }
        }
        if (!\is_string($id) || [] === $conflicts) {
            return null;
        }
        ksort($conflicts, \SORT_STRING);

        $name = $properties['name'] ?? null;
        $geometry = $feature['geometry'] ?? null;

        return new self($id, \is_string($name) && '' !== $name ? $name : null, \is_array($geometry) ? $geometry : null, $conflicts);
    }

    /**
     * Gets the name of the entity, or its id if it has no name.
     */
    public function title(): string
    {
        return $this->name ?? $this->id;
    }

    /**
     * @phpstan-assert-if-true array{value: mixed, dataSet: string, entity: string} $value
     */
    private static function isStatedValue(mixed $value): bool
    {
        return \is_array($value) && \array_key_exists('value', $value) && \is_string($value['dataSet'] ?? null) && \is_string($value['entity'] ?? null);
    }
}
