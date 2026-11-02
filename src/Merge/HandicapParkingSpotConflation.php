<?php

declare(strict_types=1);

namespace App\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\MajorityWins;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\Pipeline\ConflictResolver;
use App\Merge\Parking\WithinSiteClosestFirst;

/**
 * Disabled parking bays in Aarhus Municipality, merged from the bays and the
 * on-street sites that the municipality and OSM publish.
 *
 * Each site becomes one ParkingSpot for each of its bays. Bays within 5
 * metres of each other in different data sets, or a bay inside the area of
 * a site, are taken as the same bay. The location of a mapped bay wins over
 * the location of a site, OSM before the municipality. For other attributes,
 * the value more than half of the records state wins; without a majority,
 * the municipality's bays decide, then OSM's bays, then the sites.
 */
#[Conflation(
    id: 'merged-handicap-parking-spots',
    title: 'Handicapparkeringspladser (samlet), Aarhus Kommune',
    description: 'Disabled parking bays in Aarhus Municipality, each stated once, merged from the bays and the on-street sites of the municipality and OpenStreetMap.',
    resultModel: 'ParkingSpot',
    defining: ['ParkingSpot', 'OnStreetParking'],
    mergeByLocation: new WithinSiteClosestFirst(radius: 5.0),
    conflictResolution: [
        new SourceAuthority(['osm-handicap-parking-spot', 'mtm_spatialmaps-handicap-parking-spot'], attributes: [ConflictResolver::LOCATION]),
        new MajorityWins(),
        new SourceAuthority(['mtm_spatialmaps-handicap-parking-spot', 'osm-handicap-parking-spot', 'mtm_spatialmaps-handicap-parking-on-street', 'osm-handicap-parking-on-street']),
    ],
)]
final class HandicapParkingSpotConflation implements ConflationInterface
{
}
