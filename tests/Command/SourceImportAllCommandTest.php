<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Import\SourcesImporter;
use App\Tests\Import\FakeSourceImporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:source:import-all.
 *
 * A fake importer takes the place of the feeds and the broker.
 */
class SourceImportAllCommandTest extends KernelTestCase
{
    public function testItImportsEachSourceExceptTheTestSources(): void
    {
        $fake = $this->fake([]);

        $result = self::runCommand('app:source:import-all', normalizers: [self::oneLine(...)]);

        $this->assertCommandIsSuccessful($result);
        $this->assertContains('osm-public-toilet', $fake->imported);
        $this->assertSame([], array_filter($fake->imported, static fn (string $id): bool => str_starts_with($id, 'test:')));
        $this->assertStringContainsString('Upserted 3 entities into http://broker.example/ (HTTP 201).', $result->getDisplay());
    }

    /**
     * A scheduled run must be able to see that a source failed, so the exit
     * code tells it. The other sources are still imported.
     */
    public function testItFailsIfASourceFails(): void
    {
        $fake = $this->fake(['osm-bench']);

        $result = self::runCommand('app:source:import-all', normalizers: [self::oneLine(...)]);

        $this->assertCommandFailed($result);
        $this->assertContains('osm-public-toilet', $fake->imported);
        $this->assertStringContainsString('The feed of osm-bench is not available.', $result->getDisplay());
    }

    /**
     * Puts a fake importer in place of the real one.
     *
     * @param list<string> $failing the ids of the sources whose import fails
     */
    private function fake(array $failing): FakeSourceImporter
    {
        self::bootKernel();
        $fake = new FakeSourceImporter($failing);
        static::getContainer()->set(SourcesImporter::class, $fake->sourcesImporter());

        return $fake;
    }

    /**
     * Puts the output on one line, because the console breaks a long message into lines.
     */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
