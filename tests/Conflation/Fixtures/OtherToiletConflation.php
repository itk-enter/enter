<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;

/**
 * A second merge claiming the id of ToiletConflation.
 */
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    defining: ['ModelA'],
    mergeByLocation: new GroupedByThing(radius: 5.0),
    conflictResolution: [new PreferSource('data-set-a')],
)]
final class OtherToiletConflation implements ConflationInterface
{
}
