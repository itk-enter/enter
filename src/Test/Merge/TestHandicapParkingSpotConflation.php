<?php

declare(strict_types=1);

namespace App\Test\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\CoveringArea;
use App\Conflation\ConflictResolution\MajorityWins;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\Parking\WithinSiteClosestFirst;
use Symfony\Component\DependencyInjection\Attribute\When;

/**
 * The disabled parking merge over the test data sets.
 */
#[When('dev')]
#[When('test')]
#[Conflation(
    // By convention the ID of a test merge must start with `test:`, so it reads only test data sets
    id: 'test:merged-handicap-parking-spots',
    title: 'Test: Handicapparkeringspladser (samlet), Aarhus Kommune',
    description: 'Disabled parking bays merged from the test data sets.',
    resultModel: 'ParkingSpot',
    defining: ['ParkingSpot', 'OnStreetParking'],
    mergeByLocation: new WithinSiteClosestFirst(radius: 8.0),
    conflictResolution: [
        new CoveringArea(margin: 3.0, sourceIds: ['test:osm-handicap-parking-spot']),
        new SourceAuthority(['test:osm-handicap-parking-spot', 'test:mtm_spatialmaps-handicap-parking-spot'], attributes: [ConflictResolver::LOCATION]),
        new MajorityWins(),
        new SourceAuthority(['test:mtm_spatialmaps-handicap-parking-spot', 'test:osm-handicap-parking-spot', 'test:mtm_spatialmaps-handicap-parking-on-street', 'test:osm-handicap-parking-on-street']),
    ],
)]
final class TestHandicapParkingSpotConflation implements ConflationInterface
{
}
