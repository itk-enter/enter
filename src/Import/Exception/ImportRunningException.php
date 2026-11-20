<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * Another process imports the source now, so this import did not start.
 */
final class ImportRunningException extends \RuntimeException
{
    public function __construct(
        public readonly string $sourceId,
    ) {
        parent::__construct(\sprintf('Another import of %s runs now. This import did not start.', $sourceId));
    }
}
