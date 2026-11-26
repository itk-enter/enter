<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\EntityChange;
use App\Import\History\EntityChangeRepository;
use App\Import\History\EntityChangeType;
use App\Import\History\ImportHistory;
use App\Import\History\ImportStatus;
use App\Import\History\ImportSummary;
use App\Import\History\SourceImport;
use App\Import\History\SourceImportRepository;
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

class ImportHistoryTest extends TestCase
{
    private SourceImportRepository&Stub $imports;

    private EntityChangeRepository&Stub $changes;

    private int $nextId = 1;

    protected function setUp(): void
    {
        $this->imports = $this->createStub(SourceImportRepository::class);
        $this->changes = $this->createStub(EntityChangeRepository::class);
    }

    public function testItGroupsTheImportsOfThePeriodBySource(): void
    {
        $bench1 = $this->succeeded('bench', '2026-11-18 01:00');
        $bench2 = $this->failed('bench', '2026-11-19 01:00');
        $toilet = $this->succeeded('toilet', '2026-11-19 02:00');
        $this->imports->method('findSummariesSince')->willReturn([$bench1, $bench2, $toilet]);
        $this->imports->method('findLatestSummaries')->willReturn([$bench1, $bench2, $toilet]);

        $histories = $this->history()->bySource();

        $this->assertNotNull($histories);
        $this->assertSame(['bench', 'toilet'], array_keys($histories));
        $this->assertSame([$bench1, $bench2], $histories['bench']->imports);
        $this->assertSame($bench2, $histories['bench']->lastImport);
        $this->assertSame($bench1, $histories['bench']->lastSuccess);
        $this->assertSame([$toilet], $histories['toilet']->imports);
    }

    public function testASourceWithOnlyOldImportsHasAHistoryWithoutImportsInThePeriod(): void
    {
        $old = $this->succeeded('bench', '2026-09-01 01:00');
        $this->imports->method('findSummariesSince')->willReturn([]);
        $this->imports->method('findLatestSummaries')->willReturn([$old]);

        $histories = $this->history()->bySource();

        $this->assertNotNull($histories);
        $this->assertSame([], $histories['bench']->imports);
        $this->assertSame($old, $histories['bench']->lastImport);
        $this->assertSame($old, $histories['bench']->lastSuccess);
    }

    public function testASourceWithoutASuccessfulImportHasNoLastSuccess(): void
    {
        $failed = $this->failed('bench', '2026-11-19 01:00');
        $this->imports->method('findSummariesSince')->willReturn([$failed]);
        $this->imports->method('findLatestSummaries')->willReturn([$failed]);

        $histories = $this->history()->bySource();

        $this->assertNotNull($histories);
        $this->assertNull($histories['bench']->lastSuccess);
    }

    public function testThePeriodStartsAtMidnightThirtyDaysAgoAndTheSourceIdGoesToTheRepository(): void
    {
        $imports = $this->createMock(SourceImportRepository::class);
        $imports->expects($this->once())
            ->method('findSummariesSince')
            ->with(new \DateTimeImmutable('2026-10-21 00:00:00 UTC'), 'bench')
            ->willReturn([]);
        $imports->expects($this->once())
            ->method('findLatestSummaries')
            ->with('bench')
            ->willReturn([]);

        $histories = new ImportHistory($imports, $this->changes, new MockClock('2026-11-19 14:30:00 UTC'), $this->createStub(LoggerInterface::class))
            ->bySource('bench');

        $this->assertSame([], $histories);
    }

    public function testTheTotalsSkipTheAddedEntitiesOfAFullLoad(): void
    {
        $this->imports->method('findRemovedTotals')->willReturn(['bench' => 4, 'parking' => 0]);
        $this->changes->method('findAddedCounts')->willReturn([
            ['sourceId' => 'bench', 'upserted' => 100, 'added' => 100],
            ['sourceId' => 'bench', 'upserted' => 102, 'added' => 3],
            ['sourceId' => 'bench', 'upserted' => 104, 'added' => 2],
            ['sourceId' => 'toilet', 'upserted' => 20, 'added' => 1],
        ]);

        $this->assertSame([
            'bench' => ['added' => 5, 'removed' => 4],
            'parking' => ['added' => 0, 'removed' => 0],
            'toilet' => ['added' => 1, 'removed' => 0],
        ], $this->history()->totalsBySource());
    }

    public function testItGivesTheEntityChangesOfTheRepository(): void
    {
        $change = new EntityChange(new SourceImport('bench', new \DateTimeImmutable('2026-11-19 01:00 UTC')), 'urn:bench:1', EntityChangeType::Added);
        $changes = $this->createMock(EntityChangeRepository::class);
        $changes->expects($this->once())->method('findLatestForSource')->with('bench', 5)->willReturn([$change]);

        $history = new ImportHistory($this->imports, $changes, new MockClock(), $this->createStub(LoggerInterface::class));

        $this->assertSame([$change], $history->entityChanges('bench', 5));
    }

    public function testItLogsAndGivesNullWhenTheDatabaseFails(): void
    {
        $failure = new class('The database is not available') extends \RuntimeException implements DbalException {};
        $this->imports->method('findSummariesSince')->willThrowException($failure);
        $this->imports->method('findRemovedTotals')->willThrowException($failure);
        $this->changes->method('findLatestForSource')->willThrowException($failure);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(3))
            ->method('error')
            ->with('The import history is not available: {message}', $this->callback(
                static fn (array $context): bool => 'The database is not available' === $context['message'] && $failure === $context['exception'],
            ));

        $history = new ImportHistory($this->imports, $this->changes, new MockClock(), $logger);

        $this->assertNull($history->bySource());
        $this->assertNull($history->totalsBySource());
        $this->assertNull($history->entityChanges('bench', 5));
    }

    private function history(): ImportHistory
    {
        return new ImportHistory($this->imports, $this->changes, new MockClock('2026-11-19 14:30:00 UTC'), $this->createStub(LoggerInterface::class));
    }

    private function succeeded(string $sourceId, string $time): ImportSummary
    {
        return new ImportSummary($this->nextId++, $sourceId, $this->at($time), $this->at($time)->modify('+90 seconds'), ImportStatus::Succeeded, 'hash', 10, 0, 0, 0, null);
    }

    private function failed(string $sourceId, string $time): ImportSummary
    {
        return new ImportSummary($this->nextId++, $sourceId, $this->at($time), $this->at($time)->modify('+5 seconds'), ImportStatus::Failed, null, null, null, null, 0, 'The broker is not available');
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time.' UTC');
    }
}
