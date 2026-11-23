<?php

declare(strict_types=1);

namespace App\Tests\Test\Command;

use App\Source\Osm\Bench;
use App\SourceManager;
use App\Test\Command\SourceFetchContentCommand;
use App\Test\Fixture\FixtureFetcher;
use App\Test\Source\MtmSpatialMaps\TestToiletCity;
use App\Test\Source\Osm\TestBench;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tests the console output of test:source:fetch-content.
 *
 * The command is registered only in the dev environment. Thus the test calls
 * the command directly. The feeds are mock responses, and the fixtures go
 * into a temporary directory.
 */
class SourceFetchContentCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/source-fetch-content-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->directory);
    }

    public function testItWritesTheFixtureOfEachTestSource(): void
    {
        [$status, $display] = $this->fetchContent([new MockResponse('{"features": []}'), new MockResponse('{"elements": []}')]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertSame(2, substr_count($display, 'Wrote the content of'));
        $this->assertFileExists($this->directory.'/data/overpass-api.de/api/interpreter?osm-bench');
    }

    /**
     * A failed fetch does not stop the run. The command names the sources
     * that failed, and it exits with a failure.
     */
    public function testItNamesTheSourcesThatFailed(): void
    {
        [$status, $display] = $this->fetchContent([new MockResponse('{"features": []}'), new MockResponse('', ['http_code' => Response::HTTP_GATEWAY_TIMEOUT])]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertSame(1, substr_count($display, 'Wrote the content of'));
        $this->assertStringContainsString('Failed: test:osm-bench. The old files of these sources did not change.', $display);
    }

    /**
     * Runs the command for one GeoJSON test source, one Overpass test source
     * and one source that is not a test source.
     *
     * @param list<MockResponse> $responses the feeds of the two test sources
     *
     * @return array{int, string} the exit code and the output on one line
     */
    private function fetchContent(array $responses): array
    {
        $output = new BufferedOutput();
        $manager = new SourceManager([new TestToiletCity(), new Bench(), new TestBench()]);
        $fetcher = new FixtureFetcher(new MockHttpClient($responses), new Filesystem(), $this->directory);

        $status = new SourceFetchContentCommand()(new SymfonyStyle(new ArrayInput([]), $output), $manager, $fetcher);

        return [$status, (string) preg_replace('/\s+/', ' ', $output->fetch())];
    }
}
