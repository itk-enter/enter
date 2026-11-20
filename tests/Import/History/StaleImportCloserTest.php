<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\Event\SourceImportStartedEvent;
use App\Import\History\ImportStatus;
use App\Import\History\SourceImport;
use App\Import\History\StaleImportCloser;
use App\Source\MtmSpatialMaps\ToiletCity;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Tests that a new import of a source closes the imports of the source that
 * stayed running. A stub of the repository gives the running imports.
 */
class StaleImportCloserTest extends TestCase
{
    /**
     * @var array<string, mixed> the criteria that the closer gave to findBy()
     */
    private array $criteria = [];

    private int $flushes = 0;

    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->logger = new BufferingLogger();
    }

    public function testItFailsTheRunningImportsOfTheSource(): void
    {
        $stopped = new SourceImport('mtm_spatialmaps-toilet-city', new \DateTimeImmutable('2026-11-19 01:00:00 UTC'));

        $this->closer(running: [$stopped])(new SourceImportStartedEvent(new ToiletCity()));

        $this->assertSame(['sourceId' => 'mtm_spatialmaps-toilet-city', 'status' => ImportStatus::Running], $this->criteria);
        $this->assertSame(ImportStatus::Failed, $stopped->getStatus());
        $this->assertSame(StaleImportCloser::FAILURE, $stopped->getFailure());
        $this->assertEquals(new \DateTimeImmutable('2026-11-20 01:00:00 UTC'), $stopped->getFinishedAt());
        $this->assertSame(1, $this->flushes);
    }

    public function testItWritesNothingWithoutARunningImport(): void
    {
        $this->closer(running: [])(new SourceImportStartedEvent(new ToiletCity()));

        $this->assertSame(0, $this->flushes);
    }

    public function testADatabaseFailureDoesNotStopTheImport(): void
    {
        $this->closer(running: new \RuntimeException('Connection refused'))(new SourceImportStartedEvent(new ToiletCity()));

        $logs = $this->logger->cleanLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('error', $logs[0][0]);
        $this->assertSame('Connection refused', $logs[0][2]['message']);
    }

    /**
     * @param list<SourceImport>|\Throwable $running the running imports that the repository gives, or the error that
     *                                               it throws
     */
    private function closer(array|\Throwable $running): StaleImportCloser
    {
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('findBy')->willReturnCallback(function (array $criteria) use ($running): array {
            $this->criteria = $criteria;
            if ($running instanceof \Throwable) {
                throw $running;
            }

            return $running;
        });

        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getRepository')->willReturn($repository);
        $entityManager->method('flush')->willReturnCallback(function (): void {
            ++$this->flushes;
        });

        return new StaleImportCloser($entityManager, new MockClock('2026-11-20 01:00:00 UTC'), $this->logger);
    }
}
