<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Import\SourcesImporter;
use App\Tests\Import\FakeSourceImporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:source:import.
 *
 * A fake importer takes the place of the feed and the broker.
 */
class SourceImportCommandTest extends KernelTestCase
{
    private FakeSourceImporter $fake;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->fake = new FakeSourceImporter();
        static::getContainer()->set(SourcesImporter::class, $this->fake->sourcesImporter());
    }

    public function testItImportsTheSourceThatTheArgumentNames(): void
    {
        $result = self::runCommand('app:source:import', ['source' => 'osm-public-toilet'], normalizers: [self::oneLine(...)]);

        $this->assertCommandIsSuccessful($result);
        $this->assertSame(['osm-public-toilet'], $this->fake->imported);
        $this->assertStringContainsString(
            'Upserted 3 entities into http://broker.example/ (HTTP 201). The broker rejected 0 entities. Deleted 1 stale entities.',
            $result->getDisplay(),
        );
    }

    public function testItFailsForASourceThatDoesNotExist(): void
    {
        $this->expectExceptionMessage('The source "no-such-source" does not exist.');

        self::runCommand('app:source:import', ['source' => 'no-such-source']);
    }

    /**
     * Puts the output on one line, because the console breaks a long message into lines.
     */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
