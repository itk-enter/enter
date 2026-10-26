<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * The deletion of the stale entities failed.
 */
final class SweepFailedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
