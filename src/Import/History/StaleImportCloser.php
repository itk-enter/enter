<?php

declare(strict_types=1);

namespace App\Import\History;

use App\Import\Event\SourceImportStartedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Marks the running imports of a source as failed when a new import of the
 * source starts.
 *
 * An import stays running in the history if its process stops, for example
 * when PHP runs out of memory. SourcesImporter lets only one process at a
 * time import a source, so a running import that is older than the new one
 * has stopped.
 *
 * The listener runs before ImportRecorder records the new import. The history
 * must not stop an import. Thus, if the database fails, the listener logs the
 * error and the import continues.
 */
#[AsEventListener(priority: 10)]
final readonly class StaleImportCloser
{
    public const string FAILURE = 'The process stopped before the import finished.';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SourceImportStartedEvent $event): void
    {
        $sourceId = $event->source->definition->id;

        try {
            $stale = $this->entityManager->getRepository(SourceImport::class)->findBy([
                'sourceId' => $sourceId,
                'status' => ImportStatus::Running,
            ]);

            foreach ($stale as $import) {
                $import->fail($this->clock->now(), self::FAILURE);
            }

            if ([] !== $stale) {
                $this->entityManager->flush();
            }
        } catch (\Throwable $exception) {
            $this->logger->error('The import history could not close the stopped imports of {source}: {message}', [
                'source' => $sourceId,
                'message' => $exception->getMessage(),
                'exception' => $exception,
            ]);
        }
    }
}
