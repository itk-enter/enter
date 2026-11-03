<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\RemoteLookups;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use App\Tests\Conflation\Fixtures\NearestAddress;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class RemoteLookupsTest extends TestCase
{
    private const string T1 = 'urn:ngsi-ld:PublicToilet:merged-toilets-t-1';

    /**
     * The query of each request that the broker received.
     *
     * @var list<array<string, mixed>>
     */
    private array $queries = [];

    /**
     * Lookups over a broker that holds the given entities of the merged data
     * set, in normalized form.
     *
     * @param list<array<string, mixed>> $published
     */
    private function lookups(array $published): RemoteLookups
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($published): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $this->queries[] = $query;

            return new MockResponse(json_encode($published, \JSON_THROW_ON_ERROR));
        });

        return new RemoteLookups(new NgsiLdBroker($client, 'http://broker.example'));
    }

    /**
     * @param array<class-string, list<string>> $lookups
     */
    private static function conflation(array $lookups = [NearestAddress::class => ['address', 'postalCode']]): Conflation
    {
        return new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new GroupedByThing(5.0),
            lookups: $lookups,
        );
    }

    private static function dataSet(): MergedDataSet
    {
        return new MergedDataSet('merged-toilets', 'Merged toilets', '', 'PublicToilet', 'https://context.example/toilet.jsonld', null, []);
    }

    private static function entity(float $longitude = 10.21): NgsiEntity
    {
        return new NgsiEntity(self::T1, 'PublicToilet')->geoProperty('location', ['type' => 'Point', 'coordinates' => [$longitude, 56.15]]);
    }

    /**
     * T-1 as the merge published it before, with an address from the lookup.
     *
     * @return array<string, mixed>
     */
    private static function published(float $longitude = 10.21): array
    {
        return [
            'id' => self::T1,
            'type' => 'PublicToilet',
            'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [$longitude, 56.15]]],
            'address' => ['type' => 'Property', 'value' => 'Rådhuspladsen 2', 'observedAt' => '2026-11-02T10:15:00.000Z'],
            'postalCode' => ['type' => 'Property', 'value' => '8000', 'observedAt' => '2026-11-02T10:15:00.000Z'],
            'lookupAddresses' => ['type' => 'Property', 'value' => 'found', 'observedAt' => '2026-11-02T10:15:00.000Z'],
        ];
    }

    public function testItKeepsTheAnswersOfAnEntityAtTheSameLocation(): void
    {
        $entity = self::entity();

        $wanted = $this->lookups([self::published()])->keepAnswers(self::conflation(), self::dataSet(), [$entity]);

        $this->assertSame([self::T1 => [NearestAddress::class => ['address', 'postalCode']]], $wanted);
        $this->assertSame(['type' => 'Property', 'value' => 'Rådhuspladsen 2', 'observedAt' => '2026-11-02T10:15:00.000Z'], $entity->attribute('address'));
        $this->assertSame('8000', $entity->attribute('postalCode')['value'] ?? null);
        $this->assertSame(['type' => 'Property', 'value' => 'found', 'observedAt' => '2026-11-02T10:15:00.000Z'], $entity->attribute('lookupAddresses'));

        $this->assertCount(1, $this->queries);
        $this->assertSame('sourceId=="merged-toilets"', $this->queries[0]['q']);
        $this->assertSame('location,address,postalCode,lookupAddresses', $this->queries[0]['attrs']);
    }

    public function testAnEntityThatMovedGetsNewAnswers(): void
    {
        $entity = self::entity(10.22);

        $this->lookups([self::published()])->keepAnswers(self::conflation(), self::dataSet(), [$entity]);

        $this->assertFalse($entity->has('address'));
        $this->assertFalse($entity->has('lookupAddresses'));
    }

    public function testASmallDifferenceInTheLastDigitIsTheSameLocation(): void
    {
        $entity = self::entity(10.21);

        $this->lookups([self::published(10.2100000000001)])->keepAnswers(self::conflation(), self::dataSet(), [$entity]);

        $this->assertTrue($entity->has('address'));
    }

    public function testAValueOfTheRecordsStays(): void
    {
        $entity = self::entity()->setProperty('address', 'Park Allé 1');

        $wanted = $this->lookups([self::published()])->keepAnswers(self::conflation(), self::dataSet(), [$entity]);

        $this->assertSame([self::T1 => [NearestAddress::class => ['postalCode']]], $wanted);
        $this->assertSame('Park Allé 1', $entity->attribute('address')['value'] ?? null);
        $this->assertArrayNotHasKey('observedAt', $entity->attribute('address') ?? []);
        $this->assertSame('8000', $entity->attribute('postalCode')['value'] ?? null);
    }

    public function testItDoesNotReadTheBrokerIfTheRecordsGiveAllTheAttributes(): void
    {
        $entity = self::entity()->setProperty('address', 'Park Allé 1')->setProperty('postalCode', '8100');

        $this->assertSame([], $this->lookups([self::published()])->keepAnswers(self::conflation(), self::dataSet(), [$entity]));
        $this->assertSame([], $this->queries);
    }

    public function testAMergeWithoutLookupsDoesNotReadTheBroker(): void
    {
        $this->assertSame([], $this->lookups([self::published()])->keepAnswers(self::conflation([]), self::dataSet(), [self::entity()]));
        $this->assertSame([], $this->queries);
    }

    public function testANewEntityGetsNoAnswers(): void
    {
        $entity = self::entity();

        $wanted = $this->lookups([])->keepAnswers(self::conflation(), self::dataSet(), [$entity]);

        $this->assertSame([self::T1 => [NearestAddress::class => ['address', 'postalCode']]], $wanted);
        $this->assertFalse($entity->has('address'));
    }
}
