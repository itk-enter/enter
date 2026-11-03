<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\AsLocationLookup;
use App\Conflation\Lookup\Location;
use App\Conflation\Lookup\LocationLookupInterface;
use App\Conflation\Lookup\RemoteLookups;
use App\GeometryHelper;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBus;

/**
 * RemoteLookups::answerInline(): the run asks a lookup without a transport
 * itself, before the publish.
 */
class AnswerInlineTest extends TestCase
{
    private const string T1 = 'urn:ngsi-ld:PublicToilet:merged-toilets-t-1';

    private TestHandler $log;

    /**
     * Keeps the answers of T-1 (the broker has none) and asks the inline lookup.
     *
     * @return array<string, array<class-string, list<string>>> what markDueChecks() returns afterwards
     */
    private function answer(LocationLookupInterface $lookup, NgsiEntity $entity): array
    {
        $this->log = new TestHandler();
        $lookups = new RemoteLookups(
            new NgsiLdBroker(new MockHttpClient(new MockResponse('[]')), 'http://broker.example'),
            new MessageBus(),
            new MockClock('2026-11-20T12:00:00Z'),
            new ServiceLocator([$lookup::class => static fn (): LocationLookupInterface => $lookup]),
            new Location(new GeometryHelper()),
            new Logger('lookup', [$this->log]),
        );
        $conflation = new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'PublicToilet',
            defining: ['PublicToilet'],
            mergeByLocation: new GroupedByThing(5.0),
            lookups: [$lookup::class => ['parkingZone']],
        );
        $dataSet = new MergedDataSet('merged-toilets', 'Merged toilets', '', 'PublicToilet', 'https://context.example/toilet.jsonld', null, []);

        $wanted = $lookups->keepAnswers($conflation, $dataSet, [$entity]);
        $lookups->answerInline([$entity], $wanted);

        return $lookups->markDueChecks([$entity], $wanted);
    }

    private static function entity(): NgsiEntity
    {
        return new NgsiEntity(self::T1, 'PublicToilet')->geoProperty('location', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);
    }

    public function testTheRunAddsTheValueAtOnceAndSendsNoMessage(): void
    {
        $lookup = new #[AsLocationLookup(id: 'parking-zones', attributes: ['parkingZone'])] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): array
            {
                return ['parkingZone' => \sprintf('Zone at %F,%F', $longitude, $latitude)];
            }
        };
        $entity = self::entity();

        $due = $this->answer($lookup, $entity);

        $this->assertSame(['type' => 'Property', 'value' => 'Zone at 10.210000,56.150000', 'observedAt' => '2026-11-20T12:00:00Z'], $entity->attribute('parkingZone'));
        $this->assertSame([], $due);
        $this->assertFalse($entity->has('lookupParkingZones'));
    }

    public function testAValueOfTheRecordsStaysAndTheLookupIsNotAsked(): void
    {
        $lookup = new #[AsLocationLookup(id: 'parking-zones', attributes: ['parkingZone'])] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \LogicException('The run must not ask the lookup.');
            }
        };
        $entity = self::entity()->setProperty('parkingZone', 'Zone A');

        $this->answer($lookup, $entity);

        $this->assertSame('Zone A', $entity->attribute('parkingZone')['value'] ?? null);
        $this->assertSame([], $this->log->getRecords());
    }

    public function testAFailureIsLoggedAndDoesNotStopTheRun(): void
    {
        $lookup = new #[AsLocationLookup(id: 'parking-zones', attributes: ['parkingZone'])] class implements LocationLookupInterface {
            public function lookup(float $longitude, float $latitude): ?array
            {
                throw new \RuntimeException('The service is down.');
            }
        };
        $entity = self::entity();

        $this->answer($lookup, $entity);

        $this->assertFalse($entity->has('parkingZone'));
        $records = $this->log->getRecords();
        $this->assertCount(1, $records);
        $this->assertSame('lookup.parking-zones', $records[0]->channel);
        $this->assertSame('WARNING', $records[0]->level->getName());
        $this->assertSame('The service is down.', $records[0]->context['message']);
    }
}
