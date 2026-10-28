<?php

declare(strict_types=1);

namespace App\Conflation\Data;

/**
 * A value that one record states for an attribute, with the record and data
 * set that state it. Thus an approach can settle a conflict by the origin of
 * each value.
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
     * Whether two values are the same. It compares arrays by content and
     * order, not by identity.
     */
    public function sameValueAs(self $other): bool
    {
        return $this->value === $other->value;
    }
}
