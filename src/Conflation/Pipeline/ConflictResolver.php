<?php

declare(strict_types=1);

namespace App\Conflation\Pipeline;

use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;

/**
 * Turns a group into one merged entity, settling the attributes its members
 * disagree on with the merge's approaches.
 *
 * This part is the same for every merge: what counts as a conflict, the
 * order the approaches are tried in, what happens when none settles it, and
 * where a merged entity's identity and location come from.
 */
final readonly class ConflictResolver
{
    /**
     * The attribute name a merged entity's location is settled under.
     */
    public const string LOCATION = 'location';

    /**
     * @param list<AbstractConflictResolution> $approaches tried in order
     */
    public function __construct(
        private array $approaches,
    ) {
    }

    public function resolve(Cluster $cluster): MergedEntity
    {
        $records = $cluster->records;
        // Order the members by data set, then id, so the approaches see the
        // same values in the same order whatever order the group came in.
        usort($records, static fn (Record $x, Record $y): int => [$x->sourceId, $x->id] <=> [$y->sourceId, $y->id]);

        $ids = array_map(static fn (Record $record): string => $record->id, $records);
        sort($ids, \SORT_STRING);
        $first = $ids[0] ?? throw new \InvalidArgumentException('A group has no records.');

        // Collect what each member states, attribute by attribute. A member
        // that does not state an attribute takes no part in it.
        /** @var array<string, list<StatedValue>> $stated */
        $stated = [];
        foreach ($records as $record) {
            foreach ($record->attributes as $attribute => $value) {
                if (null !== $value) {
                    $stated[$attribute][] = new StatedValue($value, $record->id, $record->sourceId);
                }
            }
        }
        ksort($stated, \SORT_STRING);

        $attributes = [];
        $conflicts = [];
        foreach ($stated as $attribute => $values) {
            [$settled, $value] = $this->settle($attribute, $values);
            if ($settled) {
                $attributes[$attribute] = $value;
            } else {
                $conflicts[$attribute] = $values;
            }
        }

        // The location is settled like any other attribute, and falls back
        // to the first-sorting member's, so every merged entity has one.
        $locations = array_map(static fn (Record $record): StatedValue => new StatedValue($record->location(), $record->id, $record->sourceId), $records);
        [$settled, $location] = $this->settle(self::LOCATION, $locations);
        if (!$settled) {
            $location = $locations[array_search($first, array_column($locations, 'recordId'), true)]->value;
        }
        if (!\is_array($location) || !isset($location['type'])) {
            throw new ConflationLimitException(\sprintf('The location of %s was settled as %s, not as a GeoJSON geometry.', $first, get_debug_type($location)));
        }

        return new MergedEntity($first, $location, $attributes, $ids, $conflicts);
    }

    /**
     * Settles one attribute from the values stated for it.
     *
     * Values that all agree settle it without any approach. Otherwise the
     * approaches are tried in order until one settles it.
     *
     * @param list<StatedValue> $values ordered by data set, then record
     *
     * @return array{bool, mixed} whether it is settled, and its value
     */
    public function settle(string $attribute, array $values): array
    {
        if ([] === $values) {
            return [false, null];
        }

        $agreed = array_all($values, static fn (StatedValue $value): bool => $value->sameValueAs($values[0]));
        if ($agreed) {
            return [true, $values[0]->value];
        }

        foreach ($this->approaches as $approach) {
            $value = $approach->resolve($attribute, $values);
            if (null !== $value) {
                return [true, $value];
            }
        }

        return [false, null];
    }
}
