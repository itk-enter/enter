<?php

declare(strict_types=1);

namespace App\Conflation\Pipeline;

use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\Data\Cluster;
use App\Conflation\Data\MergedEntity;
use App\Conflation\Data\Record;
use App\Conflation\Data\StatedValue;

/**
 * Turns a group into one merged entity. It uses the approaches of the merge
 * to settle the attributes that the members disagree on.
 *
 * This part is the same for every merge:
 *
 * - What is a conflict.
 * - The order in which the resolver tries the approaches.
 * - What occurs if no approach settles a conflict.
 * - Where the identity and the location of a merged entity come from.
 */
final readonly class ConflictResolver
{
    /**
     * The attribute name under which the resolver settles the location of a
     * merged entity.
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
        // Order the members by data set, then id. Then the approaches see the
        // same values in the same order, in any order of the group.
        usort($records, static fn (Record $x, Record $y): int => [$x->sourceId, $x->id] <=> [$y->sourceId, $y->id]);

        $ids = array_map(static fn (Record $record): string => $record->id, $records);
        sort($ids, \SORT_STRING);
        $first = $ids[0] ?? throw new \InvalidArgumentException('A group has no records.');

        // Name each member by the input record it stands for.
        $provenance = array_values(array_unique(array_map(static fn (Record $record): string => $record->inputId(), $records)));
        sort($provenance, \SORT_STRING);

        // Collect what each member states, attribute by attribute. A member
        // that does not state an attribute takes no part in it.
        /** @var array<string, list<StatedValue>> $stated */
        $stated = [];
        foreach ($records as $record) {
            foreach ($record->attributes as $attribute => $value) {
                if (null !== $value) {
                    $stated[$attribute][] = new StatedValue($value, $record->inputId(), $record->sourceId);
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

        // Settle the location like any other attribute. If no approach
        // settles it, use the location of the member that sorts first, so
        // every merged entity has one.
        $locations = array_map(static fn (Record $record): StatedValue => new StatedValue($record->location(), $record->inputId(), $record->sourceId), $records);
        [$settled, $location] = $this->settle(self::LOCATION, $locations);
        if (!$settled) {
            $location = $locations[array_search($first, array_column($records, 'id'), true)]->value;
        }
        if (!\is_array($location) || !isset($location['type'])) {
            throw new ConflationLimitException(\sprintf('An approach settled the location of %s as %s, not as a GeoJSON geometry.', $first, get_debug_type($location)));
        }

        return new MergedEntity($first, $location, $attributes, $provenance, $conflicts);
    }

    /**
     * Settles one attribute from the values stated for it.
     *
     * If all the values agree, they settle it without an approach. If not,
     * the resolver tries the approaches in order until one settles it.
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
