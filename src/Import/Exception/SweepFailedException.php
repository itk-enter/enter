<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * The entities were upserted, but the stale ones could not be deleted.
 */
final class SweepFailedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
