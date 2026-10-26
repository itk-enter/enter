<?php

declare(strict_types=1);

namespace App\Conflation\Data;

/**
 * A value one record states for an attribute, with the record and data set
 * that state it, so a conflict can be settled by who said what.
 */
final readonly class StatedValue
{
    public function __construct(
        public mixed $value,
        public string $recordId,
        public string $sourceId,
    ) {
    }

    /**
     * Whether two values are the same, comparing arrays by content and order
     * rather than by identity.
     */
    public function sameValueAs(self $other): bool
    {
        return $this->value === $other->value;
    }
}
