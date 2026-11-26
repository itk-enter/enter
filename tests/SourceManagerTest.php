<?php

declare(strict_types=1);

namespace App\Tests;

use App\Source\Osm\Bench;
use App\Source\Osm\PicnicTable;
use App\SourceManager;
use PHPUnit\Framework\TestCase;

class SourceManagerTest extends TestCase
{
    public function testItGetsASourceByItsId(): void
    {
        $bench = new Bench();
        $manager = new SourceManager([$bench, new PicnicTable()]);

        $this->assertSame($bench, $manager->getSource('osm-bench'));
        $this->assertSame(['osm-bench', 'osm-picnic-table'], array_keys($manager->getSources()));
    }

    public function testItRejectsASourceThatDoesNotExist(): void
    {
        $this->expectExceptionMessage('The source "osm-table" does not exist.');

        new SourceManager([new Bench()])->getSource('osm-table');
    }

    public function testItRejectsAnObjectThatIsNotASource(): void
    {
        $this->expectExceptionMessage('Invalid source class: stdClass (must implement App\Source\SourceInterface)');

        new SourceManager([new \stdClass()]); // @phpstan-ignore argument.type (The test gives an object that is not a source.)
    }

    public function testItRejectsTwoSourcesWithTheSameId(): void
    {
        $this->expectExceptionMessage('Duplicate source: osm-bench (App\Source\Osm\Bench already uses the ID)');

        new SourceManager([new Bench(), new Bench()]);
    }
}
