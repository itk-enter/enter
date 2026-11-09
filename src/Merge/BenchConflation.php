<?php

declare(strict_types=1);

namespace App\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\Bench\SameKindClosestFirst;

/**
 * Benches and picnic tables in Aarhus Municipality, merged from the
 * municipality's park register GM Park and OSM.
 *
 * The merge takes two benches, or two picnic tables, in different data sets
 * within 3 metres of each other as the same object. It never joins a bench
 * and a picnic table. The location of OSM wins over the location of GM Park,
 * because the OSM points match recent aerial photos more often. If the
 * records disagree on another attribute, the merge records a conflict.
 */
#[Conflation(
    id: 'merged-benches',
    title: 'Bænke og bordbænkesæt (samlet), Aarhus Kommune',
    description: 'Benches and picnic tables in Aarhus Municipality, each stated once, merged from the municipality\'s park register GM Park and OpenStreetMap.',
    resultModel: 'Bench',
    defining: ['Bench'],
    mergeByLocation: new SameKindClosestFirst(radius: 3.0),
    conflictResolution: [
        new SourceAuthority(['osm-bench', 'osm-picnic-table', 'mtm_gmpark-bench', 'mtm_gmpark-picnic-table'], attributes: [ConflictResolver::LOCATION]),
    ],
)]
final class BenchConflation implements ConflationInterface
{
}
