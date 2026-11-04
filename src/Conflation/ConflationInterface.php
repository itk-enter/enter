<?php

declare(strict_types=1);

namespace App\Conflation;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Marks a class as a merge, so that a service tag collects the merges, as it
 * does for the sources.
 *
 * It holds no rules: a merge's configuration is its #[Conflation] attribute,
 * and its rules are the step implementations the attribute names.
 */
#[AutoconfigureTag('app.conflation')]
interface ConflationInterface
{
}
