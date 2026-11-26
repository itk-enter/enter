<?php

declare(strict_types=1);

namespace App\Import\Event;

use App\Import\ImportResult;
use App\Source\SourceInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The importer dispatches this event after it imports one source, also if
 * the import failed.
 */
final class SourceImportFinishedEvent extends Event
{
    /**
     * @param ImportResult|\Throwable $result the result of the import, or the error that stopped it
     */
    public function __construct(
        public readonly SourceInterface $source,
        public readonly ImportResult|\Throwable $result,
    ) {
    }
}
