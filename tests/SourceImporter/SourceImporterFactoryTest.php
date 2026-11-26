<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Source\Osm\Bench;
use App\SourceImporter\FindToiletSourceImporter;
use App\SourceImporter\GetJsonSourceImporter;
use App\SourceImporter\OverpassSourceImporter;
use App\SourceImporter\SourceImporterFactory;
use App\SourceManager;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests that each source gets the importer of its data type.
 */
class SourceImporterFactoryTest extends KernelTestCase
{
    public function testEachSourceGetsTheImporterOfItsDataType(): void
    {
        $factory = static::getContainer()->get(SourceImporterFactory::class);
        $sources = static::getContainer()->get(SourceManager::class)->getSources();

        $this->assertInstanceOf(OverpassSourceImporter::class, $factory->getSourceImporter($sources['osm-bench']));
        $this->assertInstanceOf(GetJsonSourceImporter::class, $factory->getSourceImporter($sources['mtm_spatialmaps-toilet-city']));
        $this->assertInstanceOf(FindToiletSourceImporter::class, $factory->getSourceImporter($sources['findtoilet-public-toilet']));
    }

    public function testItFailsIfNoImporterSupportsTheSource(): void
    {
        $this->expectExceptionMessage('No importer can import the source osm-bench.');

        new SourceImporterFactory([])->getSourceImporter(new Bench());
    }
}
