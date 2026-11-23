<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:source:list.
 */
class SourceListCommandTest extends KernelTestCase
{
    public function testItListsEachSourceWithItsTitleAndDataType(): void
    {
        $result = self::runCommand('app:source:list');

        $this->assertCommandIsSuccessful($result);
        $this->assertMatchesRegularExpression('/ osm-public-toilet +Offentlige toiletter \(OpenStreetMap\), Aarhus Kommune +Overpass /', $result->getDisplay());
        $this->assertStringContainsString(' test:osm-public-toilet ', $result->getDisplay());
    }
}
