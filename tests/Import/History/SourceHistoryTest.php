<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\ImportStatus;
use App\Import\History\ImportSummary;
use App\Import\History\SourceHistory;
use PHPUnit\Framework\TestCase;

class SourceHistoryTest extends TestCase
{
    private const string SOURCE_ID = 'osm-bench';

    private int $nextId = 1;

    public function testAnImportChangesTheDataWhenItsHashIsDifferent(): void
    {
        $imports = [
            $this->succeeded('2026-11-10 01:00', 'a'),
            $this->succeeded('2026-11-11 01:00', 'b'),
            $this->failed('2026-11-12 01:00'),
            $this->succeeded('2026-11-13 01:00', 'b'),
            $this->succeeded('2026-11-14 01:00', 'c'),
        ];

        $history = $this->history($imports);

        $this->assertSame(2, $history->dataChanges());
        $this->assertFalse($history->changedData($imports[0]));
        $this->assertTrue($history->changedData($imports[1]));
        $this->assertFalse($history->changedData($imports[2]));
        $this->assertFalse($history->changedData($imports[3]));
        $this->assertTrue($history->changedData($imports[4]));
        $this->assertSame($imports[4], $history->lastDataChange());
    }

    public function testTheDataDoesNotChangeWithTheSameHash(): void
    {
        $history = $this->history([
            $this->succeeded('2026-11-10 01:00', 'a'),
            $this->succeeded('2026-11-11 01:00', 'a'),
        ]);

        $this->assertSame(0, $history->dataChanges());
        $this->assertNull($history->lastDataChange());
    }

    public function testItCountsTheAddedAndRemovedEntitiesWithoutFullLoads(): void
    {
        $fullLoad = $this->succeeded('2026-11-10 01:00', 'a', upserted: 100, added: 100);
        $history = $this->history([
            $fullLoad,
            $this->succeeded('2026-11-11 01:00', 'b', upserted: 102, added: 3, removed: 1),
            $this->succeeded('2026-11-12 01:00', 'c', upserted: 101, removed: 1),
            $this->failed('2026-11-13 01:00'),
        ]);

        $this->assertTrue($fullLoad->isFullLoad());
        $this->assertSame(3, $history->added());
        $this->assertSame(2, $history->removed());
        $this->assertSame(1, $history->failed());
    }

    public function testAnImportWithoutEntitiesIsNotAFullLoad(): void
    {
        $this->assertFalse($this->succeeded('2026-11-10 01:00', 'a', upserted: 0)->isFullLoad());
        $this->assertFalse($this->failed('2026-11-10 01:00')->isFullLoad());
    }

    public function testItGivesEachDayOfThePeriod(): void
    {
        $history = $this->history([
            $this->succeeded('2026-11-12 01:00', 'a', added: 2),
            $this->succeeded('2026-11-12 13:00', 'b', removed: 5),
            $this->failed('2026-11-14 01:00'),
        ]);

        $this->assertCount(7, $history->days);
        $this->assertEquals(new \DateTimeImmutable('2026-11-08 00:00 UTC'), $history->days[0]->date);
        $this->assertEquals(new \DateTimeImmutable('2026-11-14 00:00 UTC'), $history->days[6]->date);

        $day = $history->days[4];
        $this->assertEquals(new \DateTimeImmutable('2026-11-12 00:00 UTC'), $day->date);
        $this->assertSame([2, 0, 2, 5], [$day->imports, $day->failed, $day->added, $day->removed]);
        $this->assertSame(0, $history->days[5]->imports);
        $this->assertSame([1, 1], [$history->days[6]->imports, $history->days[6]->failed]);
        $this->assertSame(5, $history->busiestDay());
    }

    public function testTheImportIntervalIsTheMedian(): void
    {
        $odd = $this->history([
            $this->succeeded('2026-11-13 01:00', 'a'),
            $this->succeeded('2026-11-13 02:00', 'a'),
            $this->succeeded('2026-11-13 03:00', 'a'),
            $this->succeeded('2026-11-13 07:00', 'a'),
        ]);
        $even = $this->history([
            $this->succeeded('2026-11-13 01:00', 'a'),
            $this->succeeded('2026-11-13 02:00', 'a'),
            $this->succeeded('2026-11-13 04:00', 'a'),
        ]);

        $this->assertSame(3600, $odd->importInterval());
        $this->assertSame(5400, $even->importInterval());
        $this->assertNull($this->history([$this->succeeded('2026-11-13 01:00', 'a')])->importInterval());
    }

    public function testTheEventsAreTheLastImportsThatChangedSomethingOrFailed(): void
    {
        $imports = [$this->succeeded('2026-11-08 00:00', 'a')];
        for ($hour = 1; $hour <= 12; ++$hour) {
            $imports[] = $this->succeeded(\sprintf('2026-11-09 %02d:00', $hour), 'a', added: 1);
        }
        $imports[] = $this->succeeded('2026-11-10 01:00', 'a');
        $imports[] = $this->failed('2026-11-11 01:00');
        $imports[] = $this->succeeded('2026-11-12 01:00', 'b');

        $events = $this->history($imports)->events();

        $this->assertCount(SourceHistory::EVENTS, $events);
        $this->assertSame($imports[15], $events[0]);
        $this->assertSame($imports[14], $events[1]);
        $this->assertSame($imports[12], $events[2]);
    }

    public function testItGivesTheTimeSinceTheLastImport(): void
    {
        $last = $this->succeeded('2026-11-14 10:00', 'a');

        $history = new SourceHistory(self::SOURCE_ID, $last, $last, [$last], $this->at('2026-11-08 00:00'), $this->at('2026-11-14 12:30'));

        $this->assertSame(9000, $history->sinceLastImport());
        $this->assertSame(90, $last->duration());
    }

    /**
     * @param list<ImportSummary> $imports
     */
    private function history(array $imports): SourceHistory
    {
        $last = $imports[array_key_last($imports)];

        return new SourceHistory(self::SOURCE_ID, $last, null, $imports, $this->at('2026-11-08 00:00'), $this->at('2026-11-14 12:00'));
    }

    private function succeeded(string $time, string $hash, int $upserted = 10, int $added = 0, int $removed = 0): ImportSummary
    {
        return new ImportSummary($this->nextId++, self::SOURCE_ID, $this->at($time), $this->at($time)->modify('+90 seconds'), ImportStatus::Succeeded, $hash, $upserted, 0, $removed, $added, null);
    }

    private function failed(string $time): ImportSummary
    {
        return new ImportSummary($this->nextId++, self::SOURCE_ID, $this->at($time), $this->at($time)->modify('+5 seconds'), ImportStatus::Failed, null, null, null, null, 0, 'The broker is not available');
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($time.' UTC');
    }
}
