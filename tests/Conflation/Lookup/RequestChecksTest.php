<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Conflation;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Lookup\LookupMessage;
use App\Conflation\Lookup\RemoteLookups;
use App\Ngsi\NgsiEntity;
use App\Tests\Conflation\Fixtures\NearestAddress;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * RemoteLookups::requestChecks(), which sends a message for each check that
 * is due. NearestAddress checks again after 7 days without a value, and after
 * 90 days with a value.
 */
class RequestChecksTest extends TestCase
{
    private const string T1 = 'urn:ngsi-ld:PublicToilet:merged-toilets-t-1';

    private const string NOW = '2026-11-20T12:00:00Z';

    /**
     * The messages that the run sent.
     *
     * @var list<object>
     */
    private array $sent = [];

    /**
     * Keeps the answers of T-1 from the broker, and requests the checks.
     *
     * @param array<string, mixed>|null $published T-1 as the merge published it before, or null if it is new
     */
    private function requestChecks(NgsiEntity $entity, ?array $published): int
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(json_encode(null === $published ? [] : [$published], \JSON_THROW_ON_ERROR)));
        $bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message, $stamps);
            }
        };

        $lookups = new RemoteLookups(new NgsiLdBroker($client, 'http://broker.example'), $bus, new MockClock(self::NOW));
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

        $wanted = $lookups->keepAnswers($conflation, $dataSet, [$entity]);

        $count = $lookups->requestChecks($dataSet, [$entity], $lookups->markDueChecks([$entity], $wanted));
        $this->sent = $bus->sent;

        return $count;
    }

    private static function entity(): NgsiEntity
    {
        return new NgsiEntity(self::T1, 'PublicToilet')->geoProperty('location', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function published(string $result, string $checkedAt): array
    {
        $entity = [
            'id' => self::T1,
            'type' => 'PublicToilet',
            'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [10.21, 56.15]]],
            'lookupAddresses' => ['type' => 'Property', 'value' => $result, 'observedAt' => $checkedAt],
        ];
        if ('found' === $result) {
            $entity['address'] = ['type' => 'Property', 'value' => 'Rådhuspladsen 2', 'observedAt' => $checkedAt];
        }

        return $entity;
    }

    public function testANewEntityIsDue(): void
    {
        $this->assertSame(1, $this->requestChecks(self::entity(), null));

        $this->assertEquals([new LookupMessage(
            self::T1,
            'https://context.example/toilet.jsonld',
            NearestAddress::class,
            ['address', 'postalCode'],
            ['type' => 'Point', 'coordinates' => [10.21, 56.15]],
            null,
        )], $this->sent);
    }

    #[DataProvider('checks')]
    public function testAnEntityIsDueAfterThePeriodOfItsResult(string $result, string $checkedAt, bool $due): void
    {
        $this->assertSame($due ? 1 : 0, $this->requestChecks(self::entity(), self::published($result, $checkedAt)));

        if ($due) {
            $this->assertInstanceOf(LookupMessage::class, $this->sent[0]);
            $this->assertSame($checkedAt, $this->sent[0]->checkedAt);
        }
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function checks(): iterable
    {
        yield 'no value, checked 3 days ago' => ['none', '2026-11-17T12:00:00Z', false];
        yield 'no value, checked 8 days ago' => ['none', '2026-11-12T12:00:00Z', true];
        yield 'a value, checked 8 days ago' => ['found', '2026-11-12T12:00:00Z', false];
        yield 'a value, checked 91 days ago' => ['found', '2026-08-21T12:00:00Z', true];
    }

    public function testThereIsNoCheckIfTheRecordsGiveAllTheAttributes(): void
    {
        $entity = self::entity()->setProperty('address', 'Park Allé 1')->setProperty('postalCode', '8100');

        $this->assertSame(0, $this->requestChecks($entity, null));
        $this->assertSame([], $this->sent);
    }

    public function testTheMessageAsksOnlyForTheAttributesThatTheRecordsDoNotGive(): void
    {
        $this->requestChecks(self::entity()->setProperty('address', 'Park Allé 1'), null);

        $this->assertInstanceOf(LookupMessage::class, $this->sent[0]);
        $this->assertSame(['postalCode'], $this->sent[0]->attributes);
    }
}
