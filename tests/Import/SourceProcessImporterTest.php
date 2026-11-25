<?php

declare(strict_types=1);

namespace App\Tests\Import;

use App\Import\SourceProcessImporter;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\MtmSpatialMaps\ToiletOther;
use PHPUnit\Framework\TestCase;

/**
 * Tests the import of a source in a process of its own. A short PHP script
 * acts as app:source:import: it prints the source id, and it fails for the
 * source mtm_spatialmaps-toilet-other.
 */
class SourceProcessImporterTest extends TestCase
{
    private const string SCRIPT = 'echo "Imported ", $argv[1]; exit("mtm_spatialmaps-toilet-other" === $argv[1] ? 1 : 0);';

    public function testItRunsTheImportInAProcessAndGivesItsOutput(): void
    {
        $output = '';

        $succeeded = $this->importer()->import(new ToiletCity(), static function (string $buffer) use (&$output): void {
            $output .= $buffer;
        });

        $this->assertTrue($succeeded);
        $this->assertSame('Imported mtm_spatialmaps-toilet-city', $output);
    }

    public function testItReportsAFailedImport(): void
    {
        $this->assertFalse($this->importer()->import(new ToiletOther(), static function (): void {
        }));
    }

    public function testItReportsAnImportThatRunsTooLong(): void
    {
        $importer = new SourceProcessImporter(['-r', 'sleep(30);', '--'], timeout: 1);
        $output = '';

        $succeeded = $importer->import(new ToiletCity(), static function (string $buffer) use (&$output): void {
            $output .= $buffer;
        });

        $this->assertFalse($succeeded);
        $this->assertStringContainsString('The importer stopped the process.', $output);
    }

    private function importer(): SourceProcessImporter
    {
        return new SourceProcessImporter(['-r', self::SCRIPT, '--']);
    }
}
