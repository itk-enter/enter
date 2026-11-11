<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use PHPUnit\Framework\TestCase;

/**
 * Tests when the import history tells that a data set got new data since
 * 9 November at 05:00.
 */
class ImportHistoryTest extends TestCase
{
    use InMemoryImportHistory;

    private const string SOURCE_ID = 'osm-bench';

    public function testAnotherHashIsNewData(): void
    {
        $this->assertNewData(true, [
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'b'),
        ]);
    }

    public function testTheSameHashIsNoNewData(): void
    {
        $this->assertNewData(false, [
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'a'),
        ]);
    }

    public function testAFailedImportIsNoNewData(): void
    {
        $this->assertNewData(false, [
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', null),
        ]);
    }

    public function testTheFirstSuccessfulImportIsNewData(): void
    {
        $this->assertNewData(true, [
            self::import(self::SOURCE_ID, '2026-11-08 01:00', null),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'a'),
        ]);
    }

    public function testASourceWithoutImportsHasNoNewData(): void
    {
        $this->assertNewData(false, []);
    }

    public function testTheLastImportBeforeTheTimeIsCompared(): void
    {
        $this->assertNewData(false, [
            self::import(self::SOURCE_ID, '2026-11-01 01:00', 'c'),
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'a'),
        ]);
    }

    public function testTheLastImportSinceTheTimeIsCompared(): void
    {
        $this->assertNewData(false, [
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-09 14:00', 'b'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'a'),
        ]);
    }

    public function testTheImportsOfAnotherSourceDoNotCount(): void
    {
        $this->assertNewData(false, [
            self::import(self::SOURCE_ID, '2026-11-09 01:00', 'a'),
            self::import(self::SOURCE_ID, '2026-11-10 01:00', 'a'),
            self::import('osm-picnic-table', '2026-11-10 01:00', 'b'),
        ]);
    }

    /**
     * @param list<\App\Import\History\SourceImport> $imports
     */
    private function assertNewData(bool $expected, array $imports): void
    {
        $history = $this->importHistory(...$imports);

        $this->assertSame($expected, $history->hasNewDataSince(self::SOURCE_ID, new \DateTimeImmutable('2026-11-09 05:00 UTC')));
    }
}
