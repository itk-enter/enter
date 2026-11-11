<?php

declare(strict_types=1);

namespace App\Tests\Conflation;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\ConflationFetcher;
use App\Conflation\NewDataCheck;
use App\Import\History\SourceImport;
use App\Merge\PublicToiletConflation;
use App\Source\FindToilet\PublicToilet as FindToiletPublicToilet;
use App\Source\Osm\Bench as OsmBench;
use App\Source\Osm\PublicToilet as OsmPublicToilet;
use App\SourceManager;
use App\Tests\Import\History\InMemoryImportHistory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * Tests the check on the public toilet merge, which reads the OSM and
 * FindToilet toilet data sets. The check runs on 10 November at 05:00.
 */
class NewDataCheckTest extends TestCase
{
    use InMemoryImportHistory;

    private const string OSM = 'osm-public-toilet';
    private const string FINDTOILET = 'findtoilet-public-toilet';

    public function testNewDataInOneDataSetIsEnough(): void
    {
        $this->assertTrue($this->check(
            self::import(self::OSM, '2026-11-09 01:00', 'a'),
            self::import(self::OSM, '2026-11-10 01:00', 'b'),
            self::import(self::FINDTOILET, '2026-11-09 01:00', 'c'),
            self::import(self::FINDTOILET, '2026-11-10 01:00', null),
        ));
    }

    public function testAMergeWithoutNewDataHasNoNewData(): void
    {
        $this->assertFalse($this->check(
            self::import(self::OSM, '2026-11-09 01:00', 'a'),
            self::import(self::OSM, '2026-11-10 01:00', 'a'),
            self::import(self::FINDTOILET, '2026-11-09 01:00', 'c'),
            self::import(self::FINDTOILET, '2026-11-10 01:00', 'c'),
        ));
    }

    public function testNewDataOlderThan24HoursDoesNotCount(): void
    {
        $this->assertFalse($this->check(
            self::import(self::OSM, '2026-11-08 01:00', 'a'),
            self::import(self::OSM, '2026-11-09 04:00', 'b'),
        ));
    }

    public function testNewDataInADataSetThatTheMergeDoesNotReadDoesNotCount(): void
    {
        $this->assertFalse($this->check(
            self::import(self::OSM, '2026-11-09 01:00', 'a'),
            self::import(self::OSM, '2026-11-10 01:00', 'a'),
            self::import('osm-bench', '2026-11-09 01:00', 'd'),
            self::import('osm-bench', '2026-11-10 01:00', 'e'),
        ));
    }

    private function check(SourceImport ...$imports): bool
    {
        $sources = new SourceManager([new OsmPublicToilet(), new FindToiletPublicToilet(), new OsmBench()]);
        $fetcher = new ConflationFetcher($sources, new NgsiLdBroker(new MockHttpClient(), 'http://broker.example'), new NullLogger());
        $check = new NewDataCheck($fetcher, $this->importHistory(...$imports), new MockClock('2026-11-10 05:00:00 UTC'));

        return $check->hasNewData(Conflation::of(PublicToiletConflation::class));
    }
}
