<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\Event\SourcesImportedEvent;
use App\Import\ImportResult;
use App\Import\SourcesImporter;
use App\Source\MtmSpatialMaps\HandicapParking;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\MtmSpatialMaps\ToiletOther;
use App\Source\SourceInterface;
use App\SourceImporter\SourceImporterFactory;
use App\SourceImporter\SourceImporterInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class SourcesImporterTest extends TestCase
{
    /** @var list<SourcesImportedEvent> */
    private array $dispatched = [];

    public function testItImportsEverySourceAndDispatchesTheOutcomeOnce(): void
    {
        $event = $this->importer(failing: [])->import([new ToiletCity(), new ToiletOther()]);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame($event, $this->dispatched[0]);
        $this->assertSame(['mtm_spatialmaps-toilet-city', 'mtm_spatialmaps-toilet-other'], array_keys($event->imported));
        $this->assertSame([], $event->failed);
        $this->assertFalse($event->hasFailures());
    }

    public function testAFailingSourceDoesNotStopTheRun(): void
    {
        $event = $this->importer(failing: ['mtm_spatialmaps-toilet-city'])
            ->import([new ToiletCity(), new ToiletOther()]);

        $this->assertSame(['mtm_spatialmaps-toilet-other'], array_keys($event->imported));
        $this->assertSame(['mtm_spatialmaps-toilet-city'], array_keys($event->failed));
        $this->assertSame('Feed unreachable', $event->failed['mtm_spatialmaps-toilet-city']->getMessage());
        $this->assertTrue($event->hasFailures());
    }

    public function testItReportsEachSourceAsItIsDone(): void
    {
        $reported = [];

        $this->importer(failing: ['mtm_spatialmaps-toilet-city'])->import(
            [new ToiletCity(), new ToiletOther()],
            static function (SourceInterface $source, ImportResult|\Throwable $result) use (&$reported): void {
                $reported[$source->definition->id] = $result::class;
            }
        );

        $this->assertSame([
            'mtm_spatialmaps-toilet-city' => \RuntimeException::class,
            'mtm_spatialmaps-toilet-other' => ImportResult::class,
        ], $reported);
    }

    public function testTheEventTellsWhetherAGivenSetOfSourcesAllImported(): void
    {
        $event = $this->importer(failing: ['mtm_spatialmaps-toilet-city'])
            ->import([new ToiletCity(), new ToiletOther()]);

        $this->assertTrue($event->areAllImported(['mtm_spatialmaps-toilet-other']));
        $this->assertFalse($event->areAllImported(['mtm_spatialmaps-toilet-other', 'mtm_spatialmaps-toilet-city']));

        // A source the run did not attempt is not imported either, so a
        // listener never treats a partial run as complete.
        $this->assertFalse($event->isImported((new HandicapParking())->definition->id));
        $this->assertFalse($event->areAllImported(['mtm_spatialmaps-toilet-other', 'mtm_spatialmaps-handicap-parking']));
    }

    public function testAnEmptyRunIsStillDispatched(): void
    {
        $event = $this->importer(failing: [])->import([]);

        $this->assertCount(1, $this->dispatched);
        $this->assertSame([], $event->imported);
        $this->assertTrue($event->areAllImported([]));
    }

    /**
     * @param list<string> $failing ids of the sources whose import throws
     */
    private function importer(array $failing): SourcesImporter
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(SourcesImportedEvent::class, function (SourcesImportedEvent $event): void {
            $this->dispatched[] = $event;
        });

        $fake = new class($failing) implements SourceImporterInterface {
            /**
             * @param list<string> $failing
             */
            public function __construct(private readonly array $failing)
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

                return new ImportResult(3, 201, 'http://broker.example/');
            }
        };

        return new SourcesImporter(new SourceImporterFactory([$fake]), $dispatcher);
    }
}
