<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\DBAL\Exception as DbalException;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads the import history of the sources for the /sources page and for the
 * page of each source.
 *
 * The history of a source has its last import, its last successful import,
 * and its imports of the last DAYS days. The days start at midnight in the
 * time zone of the clock. The import with the highest id is the last import,
 * because ImportRecorder saves each import when it starts.
 *
 * SourceImportRepository and EntityChangeRepository read the database. This
 * class puts their results together.
 *
 * The history must not stop the page. Thus, if the database fails, the
 * reader logs the error and returns null.
 */
final readonly class ImportHistory
{
    /**
     * The number of days in the period, today included.
     */
    public const int DAYS = 30;

    public function __construct(
        private SourceImportRepository $imports,
        private EntityChangeRepository $changes,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Gets the history of each source that has one or more imports.
     *
     * @param string|null $sourceId the id of the one source to get, or null to get all the sources
     *
     * @return array<string, SourceHistory>|null the history of each source, by source id. Null if the database
     *                                           failed.
     */
    public function bySource(?string $sourceId = null): ?array
    {
        $now = $this->clock->now();
        $since = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::DAYS - 1));

        try {
            $recent = $this->imports->findSummariesSince($since, $sourceId);
            $latest = $this->imports->findLatestSummaries($sourceId);
        } catch (DbalException $exception) {
            $this->logUnavailable($exception);

            return null;
        }

        $imports = [];
        foreach ($recent as $import) {
            $imports[$import->sourceId][] = $import;
        }

        $last = [];
        $lastSuccess = [];
        foreach ($latest as $import) {
            $last[$import->sourceId] = $import;
            if ($import->isSucceeded()) {
                $lastSuccess[$import->sourceId] = $import;
            }
        }

        $histories = [];
        foreach (array_keys($last) as $sourceId) {
            $histories[$sourceId] = new SourceHistory($sourceId, $last[$sourceId], $lastSuccess[$sourceId] ?? null, $imports[$sourceId] ?? [], $since, $now);
        }

        return $histories;
    }

    /**
     * Gets the number of entities that all the imports of each source added
     * and removed. As in SourceHistory, the added entities of a full load do
     * not count.
     *
     * @return array<string, array{added: int, removed: int}>|null the totals of each source with a successful
     *                                                             import, by source id. Null if the database failed.
     */
    public function totalsBySource(): ?array
    {
        try {
            $removed = $this->imports->findRemovedTotals();
            $added = $this->changes->findAddedCounts();
        } catch (DbalException $exception) {
            $this->logUnavailable($exception);

            return null;
        }

        $totals = [];
        foreach ($removed as $sourceId => $count) {
            $totals[$sourceId] = ['added' => 0, 'removed' => $count];
        }

        foreach ($added as $row) {
            if ($row['added'] === $row['upserted']) {
                continue;
            }

            $totals[$row['sourceId']] ??= ['added' => 0, 'removed' => 0];
            $totals[$row['sourceId']]['added'] += $row['added'];
        }

        return $totals;
    }

    /**
     * Gets the last entities that the imports of a source added or removed.
     * The last change is first.
     *
     * @param int $limit the maximum number of changes
     *
     * @return list<EntityChange>|null the changes, with their imports. Null if the database failed.
     */
    public function entityChanges(string $sourceId, int $limit): ?array
    {
        try {
            return $this->changes->findLatestForSource($sourceId, $limit);
        } catch (DbalException $exception) {
            $this->logUnavailable($exception);

            return null;
        }
    }

    private function logUnavailable(DbalException $exception): void
    {
        $this->logger->error('The import history is not available: {message}', [
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
