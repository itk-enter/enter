<?php

declare(strict_types=1);

namespace App\Tests\Test\Command;

use App\Source\Osm\Bench;
use App\SourceManager;
use App\Test\Command\SourceListCommand;
use App\Test\Source\Osm\TestBench;
use App\Test\Source\Osm\TestPicnicTable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Tests the console output of test:source:list.
 *
 * The command is registered only in the dev environment. Thus the test calls
 * the command directly.
 */
class SourceListCommandTest extends TestCase
{
    public function testItListsOnlyTheTestSources(): void
    {
        $output = new BufferedOutput();
        $manager = new SourceManager([new TestBench(), new Bench(), new TestPicnicTable()]);

        $status = new SourceListCommand()(new SymfonyStyle(new ArrayInput([]), $output), $manager);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame([
            '#sources: 2',
            (string) new TestBench(),
            (string) new TestPicnicTable(),
        ], explode("\n", trim($output->fetch())));
    }
}
