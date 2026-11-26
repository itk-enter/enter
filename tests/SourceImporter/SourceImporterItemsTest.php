<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Import\ImportResult;
use App\Source\FindToilet\PublicToilet;
use App\Source\MtmSpatialMaps\ToiletCity;
use App\Source\Osm\Bench;
use App\Source\SourceInterface;
use App\SourceImporter\FindToiletSourceImporter;
use App\SourceImporter\GetJsonSourceImporter;
use App\SourceImporter\OverpassSourceImporter;
use App\SourceReader\FeedResponse;
use App\SourceReader\SourceReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests how each importer finds the records in the feed of its data type.
 */
class SourceImporterItemsTest extends TestCase
{
    public function testFindToiletImportsEachToilet(): void
    {
        $toilet = ['id' => '862', 'title' => 'Strandvejen 19', 'location' => ['lat' => '56.139321', 'long' => '10.207297']];

        $result = $this->import(FindToiletSourceImporter::class, new PublicToilet(), ['toilets' => [$toilet]]);

        $this->assertSame(1, $result->count);
    }

    public function testFindToiletRejectsAFeedWithoutAListOfToilets(): void
    {
        $this->expectExceptionMessage('The toilets in the data are missing or not valid');

        $this->import(FindToiletSourceImporter::class, new PublicToilet(), ['toilets' => ['862' => []]]);
    }

    public function testOverpassImportsEachElement(): void
    {
        $bench = ['type' => 'node', 'id' => 1, 'lat' => 56.15, 'lon' => 10.2, 'tags' => ['amenity' => 'bench']];

        $result = $this->import(OverpassSourceImporter::class, new Bench(), ['elements' => [$bench]]);

        $this->assertSame(1, $result->count);
    }

    public function testOverpassRejectsAFeedWithoutAListOfElements(): void
    {
        $this->expectExceptionMessage('The elements in the data are missing or not valid');

        $this->import(OverpassSourceImporter::class, new Bench(), ['version' => 0.6]);
    }

    public function testGeoJsonRejectsAFeedWithoutAListOfFeatures(): void
    {
        $this->expectExceptionMessage('The features in the data are missing or not valid');

        $this->import(GetJsonSourceImporter::class, new ToiletCity(), ['type' => 'FeatureCollection']);
    }

    /**
     * Imports a feed into a broker that accepts each request.
     *
     * @param class-string<FindToiletSourceImporter|GetJsonSourceImporter|OverpassSourceImporter> $importer
     * @param array<string, mixed>                                                                $data
     */
    private function import(string $importer, SourceInterface $source, array $data): ImportResult
    {
        $client = new MockHttpClient(static fn (string $method): MockResponse => 'GET' === $method
            ? new MockResponse('[]')
            : new MockResponse('', ['http_code' => 204]));
        $reader = new readonly class($data) implements SourceReaderInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(
                private array $data,
            ) {
            }

            public function read(SourceInterface $source): FeedResponse
            {
                return new FeedResponse(json_encode($this->data, \JSON_THROW_ON_ERROR), 'application/json');
            }
        };

        return new $importer(
            $reader,
            new NgsiLdBroker($client, 'http://broker.example'),
            new Wgs84Transformer(),
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
            new NullLogger(),
        )->import($source);
    }
}
