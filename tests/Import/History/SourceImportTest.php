<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\Feed;
use App\Import\History\ImportStatus;
use App\Import\History\SourceImport;
use PHPUnit\Framework\TestCase;

class SourceImportTest extends TestCase
{
    private const string SOURCE_ID = 'mtm_spatialmaps-toilet-city';

    public function testANewImportIsRunning(): void
    {
        $import = new SourceImport(self::SOURCE_ID, $this->at('01:00'));

        $this->assertSame(self::SOURCE_ID, $import->getSourceId());
        $this->assertEquals($this->at('01:00'), $import->getStartedAt());
        $this->assertSame(ImportStatus::Running, $import->getStatus());
        $this->assertNull($import->getFinishedAt());
        $this->assertNull($import->getFeed());
    }

    public function testItRecordsASuccessfulImport(): void
    {
        $import = new SourceImport(self::SOURCE_ID, $this->at('01:00'));
        $feed = new Feed('{"features": []}', 'application/json', $this->at('01:00'));

        $import->recordFeed($feed);
        $import->succeed($this->at('01:02'), str_repeat('a', 64), 10, 1, 2);

        $this->assertSame(ImportStatus::Succeeded, $import->getStatus());
        $this->assertEquals($this->at('01:02'), $import->getFinishedAt());
        $this->assertSame($feed, $import->getFeed());
        $this->assertSame(str_repeat('a', 64), $import->getEntityHash());
        $this->assertSame(10, $import->getUpserted());
        $this->assertSame(1, $import->getRejected());
        $this->assertSame(2, $import->getDeleted());
        $this->assertNull($import->getFailure());
    }

    public function testItRecordsAFailedImport(): void
    {
        $import = new SourceImport(self::SOURCE_ID, $this->at('01:00'));

        $import->fail($this->at('01:01'), 'The broker is not available');

        $this->assertSame(ImportStatus::Failed, $import->getStatus());
        $this->assertEquals($this->at('01:01'), $import->getFinishedAt());
        $this->assertSame('The broker is not available', $import->getFailure());
        $this->assertNull($import->getEntityHash());
        $this->assertNull($import->getUpserted());
    }

    public function testAFinishedImportDoesNotChange(): void
    {
        $import = new SourceImport(self::SOURCE_ID, $this->at('01:00'));
        $import->fail($this->at('01:01'), 'The broker is not available');

        $this->expectException(\LogicException::class);

        $import->succeed($this->at('01:02'), str_repeat('a', 64), 10, 0, 0);
    }

    public function testAFinishedImportGetsNoFeed(): void
    {
        $import = new SourceImport(self::SOURCE_ID, $this->at('01:00'));
        $import->succeed($this->at('01:02'), str_repeat('a', 64), 10, 0, 0);

        $this->expectException(\LogicException::class);

        $import->recordFeed(new Feed('{}', null, $this->at('01:03')));
    }

    private function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-06 '.$time.':00 UTC');
    }
}
