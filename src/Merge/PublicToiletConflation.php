<?php

declare(strict_types=1);

namespace App\Merge;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\MajorityWins;
use App\Conflation\ConflictResolution\SourceAuthority;
use App\Conflation\MergeByLocation\OneToOneClosestFirst;

/**
 * Public toilets in Aarhus Municipality, merged from every data set that
 * publishes them: the municipality's city toilets and other toilets, OSM and
 * FindToilet.
 *
 * The merge takes toilets in different data sets within 5 metres of each
 * other as the same toilet. If they disagree, the value that more than half
 * of them state wins. If there is no majority, the municipality's city
 * toilets decide, then its other toilets. If neither of these data sets
 * states a value, the merge records a conflict.
 */
#[Conflation(
    id: 'merged-public-toilets',
    title: 'Offentlige toiletter (samlet), Aarhus Kommune',
    description: 'Public toilets in Aarhus Municipality, each stated once, merged from the municipality\'s city toilets and other toilets, OpenStreetMap and FindToilet.',
    resultModel: 'PublicToilet',
    defining: ['PublicToilet'],
    mergeByLocation: new OneToOneClosestFirst(radius: 5.0),
    conflictResolution: [
        new MajorityWins(),
        new SourceAuthority(['mtm_spatialmaps-toilet-city', 'mtm_spatialmaps-toilet-other']),
    ],
)]
final class PublicToiletConflation implements ConflationInterface
{
}
