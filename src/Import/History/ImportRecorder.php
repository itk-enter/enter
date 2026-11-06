<?php

declare(strict_types=1);

namespace App\Import\History;

use App\Import\Event\SourceImportFinishedEvent;
use App\Import\Event\SourceImportStartedEvent;
use App\Import\ImportResult;
use App\Source\SourceInterface;
use App\SourceReader\FeedResponse;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Records each import of a source in the import history.
 *
 * The recorder hears about one import at three moments. SourcesImporter
 * dispatches an event before and after the import, and RecordingSourceReader
 * gives the feed that the import read. Between these moments, the recorder
 * keeps the running SourceImport of each source.
 *
 * When an import succeeds, the recorder also writes one EntityChange for
 * each entity that the import added or deleted. A failed import gets no
 * changes, because the importer does not report them.
 *
 * The history must not stop an import. Thus, if the database fails, the
 * recorder logs the error and the import continues.
 */
final class ImportRecorder
{
    /**
     * @var array<string, SourceImport> the running imports, by source id
     */
    private array $running = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[AsEventListener]
    public function onSourceImportStarted(SourceImportStartedEvent $event): void
    {
        $sourceId = $event->source->definition->id;
        $import = new SourceImport($sourceId, $this->clock->now());
        $this->running[$sourceId] = $import;

        $this->save($sourceId, $import);
    }

    /**
     * Records the feed that the running import of the source read.
     *
     * RecordingSourceReader calls this method after each read. If the history
     * already has a feed with the same body, the import gets that feed. If no
     * import of the source runs, the method does nothing.
     */
    public function recordFeed(SourceInterface $source, FeedResponse $response): void
    {
        $sourceId = $source->definition->id;
        $import = $this->running[$sourceId] ?? null;
        if (null === $import) {
            return;
        }

        try {
            $feed = $this->entityManager->find(Feed::class, Feed::hashOf($response->body))
                ?? new Feed($response->body, $response->contentType, $this->clock->now());
        } catch (\Throwable $exception) {
            $this->logFailure($sourceId, $exception);

            return;
        }

        $import->recordFeed($feed);
        $this->save($sourceId, $feed);
    }

    #[AsEventListener]
    public function onSourceImportFinished(SourceImportFinishedEvent $event): void
    {
        $sourceId = $event->source->definition->id;
        $import = $this->running[$sourceId] ?? null;
        unset($this->running[$sourceId]);
        if (null === $import) {
            return;
        }

        $result = $event->result;
        $changes = [];
        if ($result instanceof ImportResult) {
            $rejected = \count($result->rejected);
            $import->succeed($this->clock->now(), $result->entityHash, $result->count - $rejected, $rejected, $result->deleted);
            foreach ($result->addedIds as $entityId) {
                $changes[] = new EntityChange($import, $entityId, EntityChangeType::Added);
            }
            foreach ($result->deletedIds as $entityId) {
                $changes[] = new EntityChange($import, $entityId, EntityChangeType::Removed);
            }
        } else {
            $import->fail($this->clock->now(), $result::class.': '.$result->getMessage());
        }

        $this->save($sourceId, $import, ...$changes);
    }

    private function save(string $sourceId, object ...$entities): void
    {
        try {
            foreach ($entities as $entity) {
                $this->entityManager->persist($entity);
            }
            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $this->logFailure($sourceId, $exception);
        }
    }

    private function logFailure(string $sourceId, \Throwable $exception): void
    {
        $this->logger->error('The import history could not record the import of {source}: {message}', [
            'source' => $sourceId,
            'message' => $exception->getMessage(),
            'exception' => $exception,
        ]);
    }
}
