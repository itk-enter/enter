<?php

declare(strict_types=1);

namespace App\Import\Event;

use App\Source\SourceInterface;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * The importer dispatches this event before it imports one source.
 *
 * A SourceImportFinishedEvent for the same source always follows, also if
 * the import fails.
 */
final class SourceImportStartedEvent extends Event
{
    public function __construct(
        public readonly SourceInterface $source,
    ) {
    }
}
