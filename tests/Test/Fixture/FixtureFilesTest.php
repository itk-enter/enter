<?php

declare(strict_types=1);

namespace App\Tests\Test\Fixture;

use App\Test\Fixture\FixtureFiles;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the lookup of the fixture files that the /test/data route serves.
 */
class FixtureFilesTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/fixture-files-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($this->root.'/resources/data/overpass-api.de/api/interpreter?osm-bench', '{}');
        $filesystem->dumpFile($this->root.'/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->root);
    }

    public function testItFindsAFileWithTheQueryInItsName(): void
    {
        $this->assertSame(
            realpath($this->root.'/resources/data/overpass-api.de/api/interpreter?osm-bench'),
            $this->files()->find('data/overpass-api.de/api/interpreter?osm-bench'),
        );
    }

    public function testItGivesNullForAMissingFile(): void
    {
        $this->assertNull($this->files()->find('data/overpass-api.de/api/interpreter?osm-other'));
    }

    public function testItGivesNullForAFolder(): void
    {
        $this->assertNull($this->files()->find('data'));
    }

    public function testItGivesNullForAFileOutsideTheFixtureFolder(): void
    {
        $this->assertNull($this->files()->find('../secret.txt'));
    }

    private function files(): FixtureFiles
    {
        return new FixtureFiles($this->root.'/resources');
    }
}
