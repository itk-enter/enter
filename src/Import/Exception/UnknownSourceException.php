<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * No registered source has the requested key.
 */
final class UnknownSourceException extends \InvalidArgumentException
{
    /**
     * @param list<string> $known all the registered keys
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
