<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Test\Fixture\FixtureFiles;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Tests the route that serves the fixture file of a test source.
 */
class TestControllerFixtureTest extends WebTestCase
{
    public function testItServesTheFixtureOfATestSourceAsJson(): void
    {
        $client = static::createClient();
        $client->request('GET', '/test/data/overpass-api.de/api/interpreter?osm-bench');

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    public function testItAnswersNotFoundForAFileThatIsNotAFixture(): void
    {
        $client = static::createClient();
        $client->request('GET', '/test/data/overpass-api.de/api/interpreter?no-such-source');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * A fixture whose path ends in ".geojson" has the content type of
     * GeoJSON. No test source has such a fixture yet, so the test makes one
     * in a temporary directory.
     */
    public function testItServesAGeoJsonFixtureAsGeoJson(): void
    {
        $directory = sys_get_temp_dir().'/test-controller-fixture-'.bin2hex(random_bytes(4));
        $filesystem = new Filesystem();
        $filesystem->dumpFile($directory.'/data/areas.geojson', '{"type": "FeatureCollection", "features": []}');

        try {
            $client = static::createClient();
            static::getContainer()->set(FixtureFiles::class, new FixtureFiles($directory));
            $client->request('GET', '/test/data/areas.geojson');

            $this->assertResponseIsSuccessful();
            $this->assertResponseHeaderSame('content-type', 'application/geo+json');
        } finally {
            $filesystem->remove($directory);
        }
    }
}
