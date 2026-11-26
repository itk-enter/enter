<?php

declare(strict_types=1);

namespace App\Tests\Test\Command;

use App\Source\Osm\Bench;
use App\SourceManager;
use App\Test\Command\SourcesImportCommand;
use App\Test\Source\Osm\TestBench;
use App\Test\Source\Osm\TestPicnicTable;
use App\Tests\Import\FakeSourceImporter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests the console output of test:source:import-all.
 *
 * The command is registered only in the dev environment. Thus the test calls
 * the command directly. A fake importer takes the place of the feeds and the
 * broker.
 */
class SourcesImportCommandTest extends TestCase
{
    /**
     * A failed test source does not stop the run, and the command shows the
     * reason. The command imports no source that is not a test source.
     */
    public function testItImportsEachTestSourceAndShowsTheFailures(): void
    {
        $output = new BufferedOutput();
        $fake = new FakeSourceImporter(failing: ['test:osm-picnic-table']);
        $manager = new SourceManager([new TestBench(), new Bench(), new TestPicnicTable()]);

        $status = new SourcesImportCommand()(new SymfonyStyle(new ArrayInput([]), $output), $manager, $fake->sourcesImporter());

        $display = (string) preg_replace('/\s+/', ' ', $output->fetch());
        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(['test:osm-bench'], $fake->imported);
        $this->assertStringContainsString('Upserted 3 entities into http://broker.example/ (HTTP 201).', $display);
        $this->assertStringContainsString('The feed of test:osm-picnic-table is not available.', $display);
    }
}
