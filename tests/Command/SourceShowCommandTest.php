<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:source:show.
 */
class SourceShowCommandTest extends KernelTestCase
{
    public function testItGivesTheDefinitionOfTheSourceAsYaml(): void
    {
        $result = self::runCommand('app:source:show', ['source' => 'osm-public-toilet']);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString("id: osm-public-toilet\n", $result->getDisplay());
        $this->assertStringContainsString("model: PublicToilet\n", $result->getDisplay());
    }
}
