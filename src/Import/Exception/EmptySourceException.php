<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * A source ran to the end and gave no entities.
 */
final class EmptySourceException extends \RuntimeException
{
    public function __construct(public readonly string $key)
    {
        parent::__construct(\sprintf('The source "%s" gave no entities.', $key));
    }
}
