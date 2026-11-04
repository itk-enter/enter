<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\RemoteLookups;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use App\Tests\Conflation\Fixtures\NearestAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;

/**
 * RemoteLookups::markDueChecks(), which marks a check as requested, so that
 * a later run does not send it again while it waits in the queue. The request
 * timeout of NearestAddress is the default, 1 day.
 */
class MarkDueChecksTest extends TestCase
{
    private const string T1 = 'urn:ngsi-ld:PublicToilet:merged-toilets-t-1';

    private const string NOW = '2026-11-20T12:00:00Z';

    /**
     * Keeps the answers of T-1 and marks its checks.
     *
     * @param array<string, mixed>|null $check the check property of T-1 as the merge published it before, or null if
     *                                         T-1 is new
     *
     * @return array<string, array<class-string, list<string>>> the checks that are due
     */
    private static function mark(NgsiEntity $entity, ?array $check): array
    {
        $published = null === $check ? [] : [[
            'id' => self::T1,
            'type' => 'PublicToilet',
            'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]]],
            'lookupAddresses' => $check,
        ]];
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode($published, \JSON_THROW_ON_ERROR)));
        $lookups = new RemoteLookups(new NgsiLdBroker($client, 'http://broker.example'), new MessageBus(), new MockClock(self::NOW));
        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new GroupedByThing(5.0),
            lookups: [NearestAddress::class => ['address', 'postalCode']],
        );
        $dataSet = new MergedDataSet('merged-toilets', 'Merged toilets', '', 'PublicToilet', 'https://context.example/toilet.jsonld', null, []);

        return $lookups->markDueChecks([$entity], $lookups->keepAnswers($conflation, $dataSet, [$entity]));
    }

    private static function entity(): NgsiEntity
    {
        return new NgsiEntity(self::T1, 'PublicToilet')->geoProperty('location', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);
    }

    public function testANewEntityGetsAPendingCheck(): void
    {
        $entity = self::entity();

        $due = self::mark($entity, null);

        $this->assertSame([self::T1 => [NearestAddress::class => ['address', 'postalCode']]], $due);
        $this->assertSame([
            'type' => 'Property',
            'value' => 'pending',
            'requestedAt' => ['type' => 'Property', 'value' => self::NOW],
        ], $entity->attribute('lookupAddresses'));
    }

    public function testTheMarkKeepsTheResultAndTheTimeOfTheLastCheck(): void
    {
        $entity = self::entity();

        self::mark($entity, ['type' => 'Property', 'value' => 'none', 'observedAt' => '2026-11-01T12:00:00Z']);

        $this->assertSame([
            'type' => 'Property',
            'value' => 'none',
            'observedAt' => '2026-11-01T12:00:00Z',
            'requestedAt' => ['type' => 'Property', 'value' => self::NOW],
        ], $entity->attribute('lookupAddresses'));
    }

    #[DataProvider('marks')]
    public function testARequestedCheckIsNotDueBeforeTheTimeout(string $requestedAt, bool $due): void
    {
        $entity = self::entity();
        $check = [
            'type' => 'Property',
            'value' => 'none',
            'observedAt' => '2026-11-01T12:00:00Z',
            'requestedAt' => ['type' => 'Property', 'value' => $requestedAt],
        ];

        $this->assertSame($due ? [self::T1 => [NearestAddress::class => ['address', 'postalCode']]] : [], self::mark($entity, $check));
        $this->assertSame($due ? self::NOW : $requestedAt, $entity->attribute('lookupAddresses')['requestedAt']['value'] ?? null);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function marks(): iterable
    {
        yield 'requested 3 hours ago' => ['2026-11-20T09:00:00Z', false];
        yield 'requested 25 hours ago' => ['2026-11-19T11:00:00Z', true];
    }

    public function testACheckThatIsNotDueGetsNoMark(): void
    {
        $entity = self::entity();
        $check = ['type' => 'Property', 'value' => 'none', 'observedAt' => '2026-11-19T12:00:00Z'];

        $this->assertSame([], self::mark($entity, $check));
        $this->assertSame($check, $entity->attribute('lookupAddresses'));
    }

    public function testTheRequestTimeoutIsADuration(): void
    {
        $this->assertEquals(new \DateInterval('P1D'), AsLocationLookup::of(NearestAddress::class)->requestTimeout);
    }
}
