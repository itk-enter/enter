<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\RemoteLookups;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;

/**
 * RemoteLookups::markDueChecks(), which marks a check as requested, so that
 * a later run does not send it again while it waits in the queue. The request
 * timeout of AerialPhotos is the default, 1 day.
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
            'lookupAerialPhotos' => $check,
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
            lookups: [AerialPhotos::class => ['image', 'imageDate']],
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

        $this->assertSame([self::T1 => [AerialPhotos::class => ['image', 'imageDate']]], $due);
        $this->assertSame([
            'type' => 'Property',
            'value' => 'pending',
            'requestedAt' => ['type' => 'Property', 'value' => self::NOW],
        ], $entity->attribute('lookupAerialPhotos'));
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
        ], $entity->attribute('lookupAerialPhotos'));
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

        $this->assertSame($due ? [self::T1 => [AerialPhotos::class => ['image', 'imageDate']]] : [], self::mark($entity, $check));
        $this->assertSame($due ? self::NOW : $requestedAt, $entity->attribute('lookupAerialPhotos')['requestedAt']['value'] ?? null);
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
        $this->assertSame($check, $entity->attribute('lookupAerialPhotos'));
    }

    public function testTheRequestTimeoutIsADuration(): void
    {
        $this->assertSame('P1D', AsLocationLookup::of(AerialPhotos::class)->requestTimeout);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('gives "a day" as requestTimeout');

        new AsLocationLookup(id: 'photos', attributes: ['image'], recheckEmptyAfter: 'P7D', refreshAfter: 'P90D', requestTimeout: 'a day');
    }
}
