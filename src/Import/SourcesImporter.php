<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Event\SourcesImportedEvent;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Imports several sources in one run, then announces the run's outcome.
 *
 * A failing source does not stop the run: each source is imported on its
 * own, and the event says which succeeded and which did not, so a listener
 * can decide for itself whether the run is complete enough for its purpose.
 * Failures are returned rather than logged; reporting them is the caller's
 * job.
 */
final readonly class SourcesImporter
{
    public function __construct(
        private SourceImporterFactory $factory,
        private EventDispatcherInterface $dispatcher,
    ) {
    }

    /**
     * @param iterable<SourceInterface>                                       $sources
     * @param (callable(SourceInterface, ImportResult|\Throwable): void)|null $onEach  called after each source
     */
    public function import(iterable $sources, ?callable $onEach = null): SourcesImportedEvent
    {
        $imported = [];
        $failed = [];

        foreach ($sources as $source) {
            $id = $source->definition->id;

            try {
                $result = $this->factory->getSourceImporter($source)->import($source);
                $imported[$id] = $result;
            } catch (\Throwable $exception) {
                $result = $failed[$id] = $exception;
            }

            if (null !== $onEach) {
                $onEach($source, $result);
            }
        }

        $event = new SourcesImportedEvent($imported, $failed);
        $this->dispatcher->dispatch($event);

        return $event;
    }
}
