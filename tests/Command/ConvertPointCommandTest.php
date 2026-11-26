<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:convert:point.
 */
class ConvertPointCommandTest extends KernelTestCase
{
    public function testItConvertsAPointToTheProjectionThatTheOptionNames(): void
    {
        $result = self::runCommand('app:convert:point', ['longitude' => '10.2', 'latitude' => '56.15', '--to' => 'EPSG:25832']);

        $this->assertCommandIsSuccessful($result);
        $lines = explode("\n", trim($result->getDisplay()));
        $this->assertSame('EPSG_4326', $lines[1]);
        $this->assertSame('EPSG_25832', $lines[3]);
        $this->assertMatchesRegularExpression('/^574\d{3}\.\d+ 6223\d{3}\.\d+/', $lines[2]);
    }
}
