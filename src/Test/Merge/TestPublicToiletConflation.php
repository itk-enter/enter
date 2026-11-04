<?php

declare(strict_types=1);

namespace App\Test\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\MajorityWins;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The public toilet merge over the test data sets.
 */
#[When('dev')]
#[When('test')]
#[Conflation(
    // The ID of a test merge starts with `test:`, so that the merge reads only the test data sets
    id: 'test:merged-public-toilets',
    title: 'Test: Offentlige toiletter (samlet), Aarhus Kommune',
    description: 'Public toilets merged from the test data sets.',
    resultModel: 'PublicToilet',
    defining: ['PublicToilet'],
    mergeByLocation: new OneToOneClosestFirst(radius: 5.0),
    conflictResolution: [
        new MajorityWins(),
        new SourceAuthority(['test:mtm_spatialmaps-toilet-city', 'test:mtm_spatialmaps-toilet-other']),
    ],
)]
final class TestPublicToiletConflation implements ConflationInterface
{
}
