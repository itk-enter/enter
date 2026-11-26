<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
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
        private EntityManagerInterface $entityManager,
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
     *
     * @codeCoverageIgnore The queries need MariaDB, which the tests do not have.
     */
    public function bySource(?string $sourceId = null): ?array
    {
        $now = $this->clock->now();
        $since = $now->setTime(0, 0)->modify(\sprintf('-%d days', self::DAYS - 1));

        try {
            $recent = $this->summaries($sourceId, 'i.startedAt >= :since', ['since' => $since]);
            $latest = $this->summaries(
                $sourceId,
                \sprintf(
                    'i.id IN (SELECT MAX(j.id) FROM %1$s j GROUP BY j.sourceId)
                    OR i.id IN (SELECT MAX(k.id) FROM %1$s k WHERE k.status = :succeeded GROUP BY k.sourceId)',
                    SourceImport::class,
                ),
                ['succeeded' => ImportStatus::Succeeded],
            );
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
     * Gets the last entities that the imports of a source added or removed.
     * The last change is first.
     *
     * @param int $limit the maximum number of changes
     *
     * @return list<EntityChange>|null the changes, with their imports. Null if the database failed.
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    public function entityChanges(string $sourceId, int $limit): ?array
    {
        try {
            $query = $this->entityManager
                ->createQuery(\sprintf(
                    'SELECT c, i FROM %s c JOIN c.sourceImport i WHERE i.sourceId = :sourceId ORDER BY c.id DESC',
                    EntityChange::class,
                ))
                ->setParameter('sourceId', $sourceId)
                ->setMaxResults($limit);

            /** @var list<EntityChange> $changes */
            $changes = $query->getResult();
        } catch (DbalException $exception) {
            $this->logUnavailable($exception);

            return null;
        }

        return $changes;
    }

    /**
     * Gets the imports that match a condition, oldest first.
     *
     * @param string|null          $sourceId   the id of the one source to get, or null to get all the sources
     * @param string               $condition  a DQL condition on the import "i"
     * @param array<string, mixed> $parameters the parameters of the condition
     *
     * @return list<ImportSummary>
     *
     * @codeCoverageIgnore The query needs MariaDB, which the tests do not have.
     */
    private function summaries(?string $sourceId, string $condition, array $parameters): array
    {
        if (null !== $sourceId) {
            $condition = \sprintf('i.sourceId = :sourceId AND (%s)', $condition);
            $parameters['sourceId'] = $sourceId;
        }

        $query = $this->entityManager->createQuery(\sprintf(
            'SELECT NEW %s(
                i.id, i.sourceId, i.startedAt, i.finishedAt, i.status, i.payloadHash, i.upserted, i.rejected, i.deleted,
                (SELECT COUNT(c.id) FROM %s c WHERE c.sourceImport = i AND c.type = :added),
                i.failure
            )
            FROM %s i
            WHERE %s
            ORDER BY i.startedAt, i.id',
            ImportSummary::class,
            EntityChange::class,
            SourceImport::class,
            $condition,
        ));

        $query->setParameter('added', EntityChangeType::Added);
        foreach ($parameters as $name => $value) {
            $query->setParameter($name, $value);
        }

        /** @var list<ImportSummary> $summaries */
        $summaries = $query->getResult();

        return $summaries;
    }

    private function logUnavailable(DbalException $exception): void
    {
        $this->logger->error('The import history is not available: {message}', [
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
