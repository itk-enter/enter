<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\Augmentation\WithinAreaOrRadius;
use App\Conflation\Conflation;
use App\Conflation\ConflationInterface;
use App\Conflation\ConflictResolution\SourceAuthority;

/**
 * A merge using every key of the #[Conflation] attribute, with test steps in
 * place of the defaults where a test needs to decide the result.
 */
#[Conflation(
    id: 'merged-toilets',
    title: 'Merged toilets',
    description: 'Toilets merged from several data sets.',
    resultModel: 'ResultModel',
    defining: ['ModelA', 'ModelB'],
    mergeByLocation: new GroupedByThing(radius: 5.0),
    conflictResolution: [new MostRestrictive(['wheelchair']), new SourceAuthority(['data-set-a'])],
    augmenting: ['ModelC' => ['surface']],
    augmentation: new WithinAreaOrRadius(radius: 5.0),
)]
final class ToiletConflation implements ConflationInterface
{
}
