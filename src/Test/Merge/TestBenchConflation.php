<?php

declare(strict_types=1);

namespace App\Test\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\Bench\SameKindClosestFirst;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The bench merge over the test data sets.
 */
#[When('dev')]
#[When('test')]
#[Conflation(
    // The ID of a test merge starts with `test:`, so that the merge reads only the test data sets
    id: 'test:merged-benches',
    title: 'Test: Bænke og bordbænkesæt (samlet), Aarhus Kommune',
    description: 'Benches and picnic tables merged from the test data sets.',
    resultModel: 'Bench',
    defining: ['Bench'],
    mergeByLocation: new SameKindClosestFirst(radius: 3.0),
    conflictResolution: [
        new SourceAuthority(['test:osm-bench', 'test:osm-picnic-table', 'test:mtm_gmpark-bench', 'test:mtm_gmpark-picnic-table'], attributes: [ConflictResolver::LOCATION]),
    ],
)]
final class TestBenchConflation implements ConflationInterface
{
}
