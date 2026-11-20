<?php

declare(strict_types=1);

namespace App\Import;

use App\Import\Event\SourceImportFinishedEvent;
use App\Import\Event\SourceImportStartedEvent;
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
 *
 * Each import of one source goes through importOne(), which dispatches an
 * event before and after the import. The import history listens to these
 * events.
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
                $result = $this->importOne($source);
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

    /**
     * Imports one source.
     *
     * The method dispatches SourceImportStartedEvent before the import and
     * SourceImportFinishedEvent after it. If the import fails, the method
     * dispatches the finished event with the error and then throws the
     * error again.
     */
    public function importOne(SourceInterface $source): ImportResult
    {
        $this->dispatcher->dispatch(new SourceImportStartedEvent($source));

        try {
            $result = $this->factory->getSourceImporter($source)->import($source);
        } catch (\Throwable $exception) {
            $this->dispatcher->dispatch(new SourceImportFinishedEvent($source, $exception));

            throw $exception;
        }

        $this->dispatcher->dispatch(new SourceImportFinishedEvent($source, $result));

        return $result;
    }
}
