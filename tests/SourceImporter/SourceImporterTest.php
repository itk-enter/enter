<?php

declare(strict_types=1);

namespace App\Tests\SourceImporter;

use App\Broker\NgsiLdBroker;
use App\Geo\Wgs84Transformer;
use App\Ngsi\NgsiEntity;
use App\Source\AbstractSource;
use App\Source\DataType;
use App\Source\Definition;
use App\Source\MtmSpatialMaps\HandicapParking;
use App\Source\SourceInterface;
use App\SourceImporter\GetJsonSourceImporter;
use App\SourceReader\SourceReaderInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What the import adds to every entity on its way to the broker, whichever
 * source it came from.
 */
class SourceImporterTest extends TestCase
{
    /**
     * Sources publish into shared models, so the broker cannot tell their
     * entities apart by type. The stamp is what a reader filters on, and it
     * is the source's id rather than its access URL because the id is the
     * one thing about a source that must not change.
     */
    public function testItStampsEveryEntityWithTheIdOfItsSource(): void
    {
        $source = new HandicapParking();

        $entities = $this->import($source, [$this->feature(1), $this->feature(2)]);

        $this->assertCount(2, $entities);
        foreach ($entities as $entity) {
            $this->assertSame(
                ['type' => 'Property', 'value' => $source->definition->id],
                $entity['sourceId'],
            );
        }
    }

    /**
     * The catalogue says which models a source publishes, and the map, the
     * list of sources and any reader trust it. A mapping that strays from
     * it is a bug to fix, not data to publish.
     */
    public function testItRefusesAnEntityOfAModelTheSourceDoesNotDeclare(): void
    {
        $source = new UndeclaredModelSource();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('ParkingSpot');

        $this->import($source, [$this->feature(1)]);
    }

    /**
     * Runs one import against a broker that records what it is sent, and
     * returns the entities as the broker received them.
     *
     * @param list<array<string, mixed>> $features
     *
     * @return list<array<string, mixed>>
     */
    private function import(SourceInterface $source, array $features): array
    {
        $sent = [];
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$sent): MockResponse {
            $sent = json_decode((string) $options['body'], true, flags: JSON_THROW_ON_ERROR);

            return new MockResponse('', ['http_code' => 204]);
        });

        $reader = new readonly class(['type' => 'FeatureCollection', 'features' => $features]) implements SourceReaderInterface {
            /**
             * @param array<string, mixed> $data
             */
            public function __construct(
                private array $data,
            ) {
            }

            public function read(SourceInterface $source): iterable
            {
                return $this->data;
            }
        };

        $importer = new GetJsonSourceImporter(
            $reader,
            new NgsiLdBroker($client, 'http://broker.example'),
            new Wgs84Transformer(),
            ['https://uri.etsi.org/ngsi-ld/v1/ngsi-ld-core-context.jsonld'],
            new NullLogger(),
        );
        $importer->import($source);

        return $sent;
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(int $key): array
    {
        return [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [575000, 6225000]],
            'properties' => [
                'mi_prinx' => $key,
                'vejnavn' => 'Gade',
                'husnnr' => (string) $key,
                'invalidepladser' => 2,
            ],
        ];
    }
}

/**
 * Declares one model and maps onto another.
 */
#[Definition(
    id: 'test:undeclared-model',
    title: 'Test',
    description: '',
    publisher: '',
    contact: '',
    landingPage: '',
    accessUrl: 'https://example.com',
    dataType: DataType::GeoJSON,
    mediaType: 'application/geo+json',
    crs: 'EPSG:4326',
    models: ['OnStreetParking'],
    contextUrl: 'https://example.com/context.jsonld',
    updateFrequency: '',
    licence: null,
    omittedFields: [],
)]
final class UndeclaredModelSource extends AbstractSource
{
    public function createNgsiEntity(array $data, Wgs84Transformer $transformer): NgsiEntity
    {
        return new NgsiEntity('urn:ngsi-ld:ParkingSpot:test', 'ParkingSpot');
    }
}
