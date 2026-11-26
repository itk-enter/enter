<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Import\SourceProcessImporter;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests the console output of app:source:import-all.
 *
 * The command imports each source in a process of its own. A short PHP script
 * takes the place of app:source:import. It prints the id of the source, and it
 * fails for the source that the test names.
 */
class SourceImportAllCommandTest extends KernelTestCase
{
    public function testItImportsEachSourceExceptTheTestSources(): void
    {
        $this->importer(null);

        $result = self::runCommand('app:source:import-all', normalizers: [self::oneLine(...)]);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('Imported osm-public-toilet', $result->getDisplay());
        $this->assertStringNotContainsString('Imported test:', $result->getDisplay());
    }

    /**
     * A scheduled run must be able to see that a source failed, so the exit
     * code tells it. The other sources are still imported.
     */
    public function testItFailsIfASourceFails(): void
    {
        $this->importer('osm-bench');

        $result = self::runCommand('app:source:import-all', normalizers: [self::oneLine(...)]);

        $this->assertCommandFailed($result);
        $this->assertStringContainsString('Imported osm-public-toilet', $result->getDisplay());
        $this->assertStringContainsString('The import of osm-bench failed.', $result->getDisplay());
    }

    /**
     * Puts an importer in place of the real one. It runs the short script
     * instead of app:source:import.
     *
     * @param string|null $failing the id of the source whose import fails
     */
    private function importer(?string $failing): void
    {
        self::bootKernel();
        $script = \sprintf('echo "Imported ", $argv[1], PHP_EOL; exit(%s === $argv[1] ? 1 : 0);', var_export($failing, true));
        static::getContainer()->set(SourceProcessImporter::class, new SourceProcessImporter(['-r', $script, '--']));
    }

    /**
     * Puts the output on one line, because the console breaks a long message into lines.
     */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
