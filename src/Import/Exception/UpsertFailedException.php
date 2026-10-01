<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * The payload was built, but the broker could not be written to.
 */
final class UpsertFailedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
