<?php

declare(strict_types=1);

namespace App\Conflation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks a class as a merge, so merges are collected by a service tag the way
 * sources are.
 *
 * It holds no rules: a merge's configuration is its #[Conflation] attribute,
 * and its rules are the step implementations the attribute names.
 */
#[AutoconfigureTag('app.conflation')]
interface ConflationInterface
{
}
