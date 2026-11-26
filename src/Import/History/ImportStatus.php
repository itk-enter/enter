<?php

declare(strict_types=1);

namespace App\Import\History;

/**
 * The status of one import of one source.
 */
enum ImportStatus: string
{
    /**
     * The import started and did not finish yet. If the process stops during
     * the import, the status stays running until the next import of the
     * source starts. Then StaleImportCloser marks it as failed.
     */
    case Running = 'running';

    /**
     * The import upserted the entities and deleted the stale entities.
     */
    case Succeeded = 'succeeded';

    /**
     * The import stopped because of an error.
     */
    case Failed = 'failed';
}
