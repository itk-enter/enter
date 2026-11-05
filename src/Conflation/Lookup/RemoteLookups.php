<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Ngsi\NgsiEntity;

/**
 * Keeps the answers of the lookups of a merge on its merged entities.
 *
 * A run builds each merged entity again from the records. The answers of the
 * lookups are not in the records, so the run would publish the entity
 * without them. Thus, before the runner publishes, this class copies the
 * answers, and the check property of each lookup, from the entity that the
 * merge published before. It copies them only if the entity has the same id
 * and the same location. A merged entity that moves gets new answers.
 *
 * A lookup supplies only the attributes that the records of the thing do not
 * give. A value of the records always stays.
 */
final readonly class RemoteLookups
{
    /**
     * The decimal places of a coordinate that the location test compares.
     *
     * The test compares the location of this run with the location that the
     * broker gives back as JSON. The two can be different in the format of
     * the number, for example 10 and 10.0, or in the last digits of a long
     * decimal. Without the rounding, the test can find that an entity moved
     * when it did not. Then the entity loses its answers, and the lookup is
     * asked again on each run. 9 decimal places of a degree is approximately
     * 0.1 mm. This is much less than the radius of a merge.
     */
    private const int PRECISION = 9;

    public function __construct(
        private NgsiLdBroker $broker,
    ) {
    }

    /**
     * Copies the answers of the lookups from the published entities.
     *
     * The runner calls the method after it creates the merged entities, and
     * before it publishes them. If the merge names no lookups, the method
     * does not read the broker.
     *
     * @param list<NgsiEntity> $entities the merged entities of this run; the method adds the answers to them
     *
     * @return array<string, array<class-string, list<string>>> by entity id, and then by lookup class: the attributes
     *                                                          that the lookup must supply, because the records do not
     *                                                          give them
     */
    public function keepAnswers(Conflation $conflation, MergedDataSet $dataSet, array $entities): array
    {
        $wanted = [];
        foreach ($entities as $entity) {
            foreach ($conflation->lookups as $class => $attributes) {
                $missing = array_values(array_filter($attributes, static fn (string $attribute): bool => !$entity->has($attribute)));
                if ([] !== $missing) {
                    $wanted[$entity->id()][$class] = $missing;
                }
            }
        }

        if ([] === $wanted) {
            return [];
        }

        $previous = iterator_to_array($this->broker->entities($dataSet->id, $dataSet->contextUrl, self::attributes($conflation)));
        foreach ($entities as $entity) {
            $before = $previous[$entity->id()] ?? null;
            if (null === $before || !self::sameLocation($entity->attribute('location'), $before['location'] ?? null)) {
                continue;
            }

            foreach ($wanted[$entity->id()] ?? [] as $class => $attributes) {
                foreach ([...$attributes, AsLocationLookup::of($class)->checkProperty()] as $attribute) {
                    self::copy($entity, $attribute, $before[$attribute] ?? null);
                }
            }
        }

        return $wanted;
    }

    /**
     * The attributes to read from the published entities: the location, and
     * the attributes and the check property of each lookup.
     *
     * @return list<string>
     */
    private static function attributes(Conflation $conflation): array
    {
        $attributes = ['location'];
        foreach ($conflation->lookups as $class => $supplied) {
            $attributes = [...$attributes, ...$supplied, AsLocationLookup::of($class)->checkProperty()];
        }

        return $attributes;
    }

    private static function copy(NgsiEntity $entity, string $name, mixed $attribute): void
    {
        if (!\is_array($attribute) || 'Property' !== ($attribute['type'] ?? null) || !\array_key_exists('value', $attribute)) {
            return;
        }

        $observedAt = $attribute['observedAt'] ?? null;
        $entity->setProperty($name, $attribute['value'], \is_string($observedAt) ? $observedAt : null);
    }

    /**
     * Tests if two GeoProperties hold the same geometry.
     */
    private static function sameLocation(mixed $location, mixed $other): bool
    {
        if (!\is_array($location) || !\is_array($other)) {
            return false;
        }

        return self::rounded($location['value'] ?? null) === self::rounded($other['value'] ?? null);
    }

    /**
     * The geometry with each coordinate rounded to PRECISION.
     */
    private static function rounded(mixed $value): mixed
    {
        if (\is_int($value) || \is_float($value)) {
            return round((float) $value, self::PRECISION);
        }

        if (\is_array($value)) {
            return array_map(self::rounded(...), $value);
        }

        return $value;
    }
}
