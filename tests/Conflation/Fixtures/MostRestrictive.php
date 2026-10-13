<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Fixtures;

use App\Conflation\ConflictResolution\AbstractConflictResolution;
use App\Conflation\StatedValue;

/**
 * An example customization: "no" wins for the named attributes if any member
 * says so, and every other attribute is left to the next approach.
 */
final class MostRestrictive extends AbstractConflictResolution
{
    /**
     * @param list<string> $attributes
     */
    public function __construct(
        private readonly array $attributes,
    ) {
    }

    public function resolve(string $attribute, array $values): mixed
    {
        if (!\in_array($attribute, $this->attributes, true)) {
            return null;
        }

        return array_any($values, static fn (StatedValue $value): bool => 'no' === $value->value) ? 'no' : null;
    }
}
