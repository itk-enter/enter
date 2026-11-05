<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Event\SourcesImportedEvent;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Imports several sources in one run, and then dispatches the result of the
 * run.
 *
 * If a source fails, the run does not stop. The importer imports each source
 * separately. The event tells which sources the importer imported and which
 * sources failed. Thus a listener can decide if the run is sufficiently
 * complete for its purpose. The importer returns the failures and does not
 * log them. The caller reports them.
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
