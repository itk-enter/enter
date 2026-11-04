<?php

declare(strict_types=1);

namespace App\Conflation\EventListener;

use App\Conflation\ConflationManager;
use App\Conflation\ConflationRunner;
use App\Conflation\Io\ConflationFetcher;
use App\Import\Event\SourcesImportedEvent;
use App\Source\SourceInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Runs and publishes every merge whose data sets an import run has just
 * brought up to date.
 */
#[AsEventListener]
final readonly class RunConflationsListener
{
    public function __construct(
        private ConflationManager $conflations,
        private ConflationFetcher $fetcher,
        private ConflationRunner $runner,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SourcesImportedEvent $event): void
    {
        foreach ($this->conflations->getConflations() as $conflation) {
            $inputs = array_map(static fn (SourceInterface $source): string => $source->definition->id, $this->fetcher->dataSets($conflation));
            $attempted = array_values(array_filter($inputs, static fn (string $id): bool => $event->isImported($id) || isset($event->failed[$id])));

            if ([] === $attempted) {
                continue;
            }

            if (!$event->areAllImported($inputs)) {
                $this->logger->warning('Skipped merge {merge}: not every data set it reads was imported ({missing}).', [
                    'merge' => $conflation->id,
                    'missing' => implode(', ', array_filter($inputs, static fn (string $id): bool => !$event->isImported($id))),
                ]);
                continue;
            }

            try {
                $this->runner->run($conflation);
            } catch (\Throwable $exception) {
                $this->logger->error('Merge {merge} failed: {message}', ['merge' => $conflation->id, 'message' => $exception->getMessage(), 'exception' => $exception]);
            }
        }
    }
}
