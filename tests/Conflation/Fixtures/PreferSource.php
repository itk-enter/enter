<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\StatedValue;

/**
 * Settles a conflict by one data set's value, where it states one.
 */
final class PreferSource extends AbstractConflictResolution
{
    public function __construct(
        private readonly string $sourceId,
    ) {
    }

    public function resolve(string $attribute, array $values): mixed
    {
        return array_find($values, fn (StatedValue $value): bool => $value->sourceId === $this->sourceId)?->value;
    }
}
