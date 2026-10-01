<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * No source is registered under the requested key.
 */
final class UnknownSourceException extends \InvalidArgumentException
{
    /**
     * @param list<string> $known every key that is registered
     */
    public function __construct(
        public readonly string $key,
        public readonly array $known,
    ) {
        parent::__construct(\sprintf(
            'Unknown source "%s". Available: %s.',
            $key,
            [] === $known ? 'none' : implode(', ', $known)
        ));
    }
}
