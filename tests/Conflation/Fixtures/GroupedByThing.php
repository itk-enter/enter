<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Data\Cluster;
use App\Conflation\MergeByLocation\AbstractMergeByLocation;

/**
 * Groups records by the "thing" attribute a test gives them, or alone where
 * they have none, so pipeline tests decide the groups without positions.
 */
final class GroupedByThing extends AbstractMergeByLocation
{
    public function merge(array $records): array
    {
        $groups = [];
        foreach ($records as $record) {
            $thing = $record->attributes['thing'] ?? $record->id;
            $groups[\is_string($thing) ? $thing : $record->id][] = $record;
        }
        ksort($groups, \SORT_STRING);

        return array_values(array_map(static fn (array $records): Cluster => new Cluster($records), $groups));
    }
}
