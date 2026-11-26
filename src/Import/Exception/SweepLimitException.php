<?php

declare(strict_types=1);

namespace App\Import\Exception;

/**
 * The import would delete too large a share of the entities of the source,
 * so it stopped before it changed anything in the broker.
 *
 * A feed that suddenly gives far fewer records is more often broken than
 * true. The stale entities stay until an import with a higher limit accepts
 * the deletion.
 */
final class SweepLimitException extends \RuntimeException
{
    /**
     * @param int   $stale the number of entities that the import would delete
     * @param int   $held  the number of entities of the source in the broker
     * @param float $limit the largest share that one import may delete, from 0 to 1
     */
    public function __construct(
        public readonly string $sourceId,
        public readonly int $stale,
        public readonly int $held,
        public readonly float $limit,
    ) {
        parent::__construct(\sprintf(
            'The feed of %s would delete %d of the %d entities of the source, more than %d %%. The import changed nothing. To accept the deletion, run the import with APP_IMPORT_SWEEP_LIMIT=1.',
            $sourceId,
            $stale,
            $held,
            (int) round($limit * 100),
        ));
    }
}
