<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Broker\Rejection;
use App\Import\Event\SourceImportFinishedEvent;
use App\Import\Event\SourceImportStartedEvent;
use App\Import\History\EntityChange;
use App\Import\History\Feed;
use App\Import\History\ImportRecorder;
use App\Import\History\ImportStatus;
use App\Import\History\SourceImport;
use App\Import\ImportResult;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\SourceReader\FeedResponse;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Tests what the recorder stores. A stub of the entity manager keeps each
 * persisted object, and find() returns the feeds that a test gives it.
 */
class ImportRecorderTest extends TestCase
{
    private const string BODY = '{"type": "FeatureCollection", "features": []}';

    /** @var list<object> */
    private array $persisted = [];

    /** @var array<string, Feed> the stored feeds, by hash */
    private array $storedFeeds = [];

    private ?\Throwable $flushFailure = null;

    private MockClock $clock;

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-07 01:00:00 UTC');
        $this->logger = new BufferingLogger();
    }

    public function testTheStartRecordsARunningImport(): void
    {
        $this->recorder()->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $import = $this->import();
        $this->assertSame('mtm_spatialmaps-toilet-city', $import->getSourceId());
        $this->assertSame(ImportStatus::Running, $import->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-11-07 01:00:00 UTC'), $import->getStartedAt());
    }

    public function testItRecordsTheFeedOfTheRunningImport(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $recorder->recordFeed(new ToiletCity(), new FeedResponse(self::BODY, 'application/json'));

        $feed = $this->import()->getFeed();
        $this->assertNotNull($feed);
        $this->assertSame(Feed::hashOf(self::BODY), $feed->getHash());
        $this->assertSame(self::BODY, $feed->getBody());
        $this->assertContains($feed, $this->persisted);
    }

    public function testAStoredBodyIsNotStoredAgain(): void
    {
        $stored = new Feed(self::BODY, 'application/json', new \DateTimeImmutable('2026-11-01 01:00:00 UTC'));
        $this->storedFeeds[$stored->getHash()] = $stored;
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $recorder->recordFeed(new ToiletCity(), new FeedResponse(self::BODY, 'application/json'));

        $this->assertSame($stored, $this->import()->getFeed());
        $this->assertSame([$stored], array_values(array_filter($this->persisted, static fn (object $object): bool => $object instanceof Feed)));
    }

    public function testAFeedWithoutARunningImportIsNotRecorded(): void
    {
        $this->recorder()->recordFeed(new ToiletCity(), new FeedResponse(self::BODY, 'application/json'));

        $this->assertSame([], $this->persisted);
    }

    public function testItRecordsASuccessfulImport(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));
        $this->clock->sleep(90);
        $result = new ImportResult(10, 2, 201, 'http://broker.example/', str_repeat('a', 64), [
            'urn:ngsi-ld:PublicToilet:aarhus-toilet-city-7' => new Rejection('BadRequestData', []),
        ]);

        $recorder->onSourceImportFinished(new SourceImportFinishedEvent(new ToiletCity(), $result));

        $import = $this->import();
        $this->assertSame(ImportStatus::Succeeded, $import->getStatus());
        $this->assertEquals(new \DateTimeImmutable('2026-11-07 01:01:30 UTC'), $import->getFinishedAt());
        $this->assertSame(str_repeat('a', 64), $import->getEntityHash());
        $this->assertSame(9, $import->getUpserted());
        $this->assertSame(1, $import->getRejected());
        $this->assertSame(2, $import->getDeleted());
    }

    public function testItRecordsAFailedImport(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $recorder->onSourceImportFinished(new SourceImportFinishedEvent(new ToiletCity(), new \RuntimeException('Feed unreachable')));

        $import = $this->import();
        $this->assertSame(ImportStatus::Failed, $import->getStatus());
        $this->assertSame('RuntimeException: Feed unreachable', $import->getFailure());
    }

    public function testASuccessfulImportRecordsTheEntitiesThatItAddedAndDeleted(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));
        $result = new ImportResult(2, 1, 201, 'http://broker.example/', str_repeat('a', 64), [], [
            'urn:ngsi-ld:PublicToilet:aarhus-toilet-city-8',
        ], [
            'urn:ngsi-ld:PublicToilet:aarhus-toilet-city-3',
        ]);

        $recorder->onSourceImportFinished(new SourceImportFinishedEvent(new ToiletCity(), $result));

        $changes = $this->changes();
        $this->assertSame([
            'added urn:ngsi-ld:PublicToilet:aarhus-toilet-city-8',
            'removed urn:ngsi-ld:PublicToilet:aarhus-toilet-city-3',
        ], array_map(static fn (EntityChange $change): string => $change->getType()->value.' '.$change->getEntityId(), $changes));
        $this->assertSame($this->import(), $changes[0]->getSourceImport());
    }

    public function testAFailedImportRecordsNoChanges(): void
    {
        $recorder = $this->recorder();
        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));

        $recorder->onSourceImportFinished(new SourceImportFinishedEvent(new ToiletCity(), new \RuntimeException('Feed unreachable')));

        $this->assertSame([], $this->changes());
    }

    public function testAFailureOfTheDatabaseIsLoggedAndTheImportContinues(): void
    {
        $this->flushFailure = new \RuntimeException('Connection refused');
        $recorder = $this->recorder();

        $recorder->onSourceImportStarted(new SourceImportStartedEvent(new ToiletCity()));
        $recorder->onSourceImportFinished(new SourceImportFinishedEvent(new ToiletCity(), new \RuntimeException('Feed unreachable')));

        $logs = $this->logger->cleanLogs();
        $this->assertCount(2, $logs);
        $this->assertSame('error', $logs[0][0]);
        $this->assertSame('mtm_spatialmaps-toilet-city', $logs[0][2]['source']);
        $this->assertSame('Connection refused', $logs[0][2]['message']);
    }

    private function recorder(): ImportRecorder
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $object): void {
            $this->persisted[] = $object;
        });
        $entityManager->method('find')->willReturnCallback(fn (string $class, mixed $id): ?object => Feed::class === $class ? $this->storedFeeds[$id] ?? null : null);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            if (null !== $this->flushFailure) {
                throw $this->flushFailure;
            }
        });

        return new ImportRecorder($entityManager, $this->clock, $this->logger);
    }

    /**
     * Gets the entity changes that the recorder persisted.
     *
     * @return list<EntityChange>
     */
    private function changes(): array
    {
        return array_values(array_filter($this->persisted, static fn (object $object): bool => $object instanceof EntityChange));
    }

    /**
     * Gets the one import that the recorder persisted.
     */
    private function import(): SourceImport
    {
        $imports = array_values(array_filter($this->persisted, static fn (object $object): bool => $object instanceof SourceImport));
        $this->assertCount(1, array_unique(array_map(spl_object_id(...), $imports)));

        return $imports[0];
    }
}
