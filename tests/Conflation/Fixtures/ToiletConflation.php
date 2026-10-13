<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;

/**
 * A merge using every key of the #[Conflation] attribute, with test steps in
 * place of the defaults.
 */
#[Conflation(
    id: 'merged-toilets',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    mergeByLocation: new GroupedByThing(radius: 5.0),
    conflictResolution: [new MostRestrictive(['wheelchair']), new PreferSource('data-set-a')],
    augmenting: ['ModelC' => ['surface']],
    augmentation: new SuppliesEveryEntity(radius: 5.0),
)]
final class ToiletConflation implements ConflationInterface
{
}
