<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\SourceAuthority;

/**
 * A second merge claiming the id of ToiletConflation.
 */
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    defining: ['ModelA'],
    mergeByLocation: new GroupedByThing(radius: 5.0),
    conflictResolution: [new SourceAuthority(['data-set-a'])],
)]
final class OtherToiletConflation implements ConflationInterface
{
}
