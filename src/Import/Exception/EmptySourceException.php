<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * A source ran to completion and yielded no entities.
 */
final class EmptySourceException extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf('Source "%s" produced no entities.', $key));
    }
}
