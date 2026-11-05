<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * The importer made the payload, but it could not write the payload to the
 * broker.
 */
final class UpsertFailedException extends \RuntimeException
{
    public function __construct(\Throwable $previous)
    {
        parent::__construct($previous->getMessage(), previous: $previous);
    }
}
