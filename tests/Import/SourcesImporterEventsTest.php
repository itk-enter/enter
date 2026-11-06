<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\Event\SourceImportFinishedEvent;
use App\Import\Event\SourceImportStartedEvent;
use App\Import\Event\SourcesImportedEvent;
use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\MtmSpatialMaps\ToiletOther;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use App\SourceImporter\SourceImporterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Tests the events that the importer dispatches for each source.
 */
class SourcesImporterEventsTest extends TestCase
{
    /** @var list<string> the dispatched events, as "event class: source id" */
    private array $dispatched = [];

    /** @var list<SourceImportFinishedEvent> */
    private array $finished = [];

    public function testImportOneDispatchesAnEventBeforeAndAfterTheImport(): void
    {
        $result = $this->importer(failing: [])->importOne(new ToiletCity());

        $this->assertSame([
            'SourceImportStartedEvent: mtm_spatialmaps-toilet-city',
            'SourceImportFinishedEvent: mtm_spatialmaps-toilet-city',
        ], $this->dispatched);
        $this->assertSame($result, $this->finished[0]->result);
    }

    public function testAFailedImportOneDispatchesTheErrorAndThrowsIt(): void
    {
        $thrown = null;
        try {
            $this->importer(failing: ['mtm_spatialmaps-toilet-city'])->importOne(new ToiletCity());
        } catch (\RuntimeException $exception) {
            $thrown = $exception;
        }

        $this->assertInstanceOf(\RuntimeException::class, $thrown);
        $this->assertSame('Feed unreachable', $thrown->getMessage());
        $this->assertCount(1, $this->finished);
        $this->assertSame($thrown, $this->finished[0]->result);
    }

    public function testImportDispatchesTheEventsOfEachSourceBeforeTheEventOfTheRun(): void
    {
        $this->importer(failing: ['mtm_spatialmaps-toilet-other'])->import([new ToiletCity(), new ToiletOther()]);

        $this->assertSame([
            'SourceImportStartedEvent: mtm_spatialmaps-toilet-city',
            'SourceImportFinishedEvent: mtm_spatialmaps-toilet-city',
            'SourceImportStartedEvent: mtm_spatialmaps-toilet-other',
            'SourceImportFinishedEvent: mtm_spatialmaps-toilet-other',
            'SourcesImportedEvent',
        ], $this->dispatched);
    }

    /**
     * @param list<string> $failing the ids of the sources whose import fails
     */
    private function importer(array $failing): SourcesImporter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SourceImportStartedEvent::class, function (SourceImportStartedEvent $event): void {
            $this->dispatched[] = 'SourceImportStartedEvent: '.$event->source->definition->id;
        });
        $dispatcher->addListener(SourceImportFinishedEvent::class, function (SourceImportFinishedEvent $event): void {
            $this->dispatched[] = 'SourceImportFinishedEvent: '.$event->source->definition->id;
            $this->finished[] = $event;
        });
        $dispatcher->addListener(SourcesImportedEvent::class, function (): void {
            $this->dispatched[] = 'SourcesImportedEvent';
        });

        $fake = new readonly class($failing) implements SourceImporterInterface {
            /**
             * @param list<string> $failing
             */
            public function __construct(private array $failing)
            {
            }

            public function supports(SourceInterface $source): bool
            {
                return true;
            }

            public function import(SourceInterface $source): ImportResult
            {
                if (\in_array($source->definition->id, $this->failing, true)) {
                    throw new \RuntimeException('Feed unreachable');
                }

                return new ImportResult(3, 0, 201, 'http://broker.example/', str_repeat('a', 64));
            }
        };

        return new SourcesImporter(new SourceImporterFactory([$fake]), $dispatcher);
    }
}
