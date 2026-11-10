<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\Event\SourceImportStartedEvent;
use App\Import\History\Feed;
use App\Import\History\ImportRecorder;
use App\Import\History\RecordingSourceReader;
use App\Import\History\SourceImport;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\SourceInterface;
use App\SourceReader\FeedResponse;
use App\SourceReader\SourceReaderInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

class RecordingSourceReaderTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    public function testItGivesTheResponseOfTheReader(): void
    {
        $response = new FeedResponse('{"features": []}', 'application/json');

        $this->assertSame($response, $this->reader($response, $this->recorder())->read(new ToiletCity()));
    }

    public function testItGivesTheFeedToTheRunningImport(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $this->reader(new FeedResponse('{"features": []}', 'application/json'), $recorder)->read(new ToiletCity());

        $imports = array_values(array_filter($this->persisted, static fn (object $object): bool => $object instanceof SourceImport));
        $this->assertSame(Feed::hashOf('{"features": []}'), $imports[0]->getFeed()?->getHash());
    }

    private function reader(FeedResponse $response, ImportRecorder $recorder): RecordingSourceReader
    {
        $inner = new readonly class($response) implements SourceReaderInterface {
            public function __construct(
                private FeedResponse $response,
            ) {
            }

            public function read(SourceInterface $source): FeedResponse
            {
                return $this->response;
            }
        };

        return new RecordingSourceReader($inner, $recorder);
    }

    private function recorder(): ImportRecorder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });

        return new ImportRecorder($entityManager, new MockClock('2026-11-07 01:00:00 UTC'), new NullLogger());
    }
}
