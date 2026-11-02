<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Broker\NgsiLdBroker;
use App\Conflation\Lookup\Location;
use App\Conflation\Lookup\LookupHandler;
use App\Conflation\Lookup\LookupMessage;
use App\GeometryHelper;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class LookupHandlerTest extends TestCase
{
    private const string T1 = 'urn:ngsi-ld:PublicToilet:merged-toilets-t-1';

    /**
     * The body of each update that the broker received, by entity id.
     *
     * @var list<array{string, mixed}>
     */
    private array $updates = [];

    /**
     * A handler over a broker that holds T-1 as given, or does not hold it.
     *
     * @param array<string, mixed>|null $entity T-1 in normalized form, or null if it does not exist
     */
    private function handler(?array $entity): LookupHandler
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($entity): MockResponse {
            $path = rawurldecode((string) parse_url($url, \PHP_URL_PATH));
            if ('POST' === $method) {
                $this->updates[] = [$path, json_decode((string) $options['body'], true)];

                return new MockResponse('', ['http_code' => null === $entity ? 404 : 204]);
            }

            return null === $entity
                ? new MockResponse('{"type": "https://uri.etsi.org/ngsi-ld/errors/ResourceNotFound"}', ['http_code' => 404])
                : new MockResponse(json_encode($entity, \JSON_THROW_ON_ERROR));
        });

        return new LookupHandler(
            new ServiceLocator([AerialPhotos::class => static fn (): AerialPhotos => new AerialPhotos()]),
            new NgsiLdBroker($client, 'http://broker.example'),
            new Location(new GeometryHelper()),
            new MockClock('2026-11-20T12:00:00Z'),
            new NullLogger(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function entity(float $longitude, ?string $checkedAt = null): array
    {
        $entity = [
            'id' => self::T1,
            'type' => 'PublicToilet',
            'location' => ['type' => 'GeoProperty', 'value' => ['type' => 'Point', 'coordinates' => [$longitude, 56.15]]],
        ];
        if (null !== $checkedAt) {
            $entity['lookupAerialPhotos'] = ['type' => 'Property', 'value' => 'none', 'observedAt' => $checkedAt];
        }

        return $entity;
    }

    private static function message(float $longitude, ?string $checkedAt = null): LookupMessage
    {
        return new LookupMessage(
            self::T1,
            'https://context.example/toilet.jsonld',
            AerialPhotos::class,
            ['image', 'imageDate'],
            ['type' => 'Point', 'coordinates' => [$longitude, 56.15]],
            $checkedAt,
        );
    }

    public function testItWritesTheValuesAndTheCheckProperty(): void
    {
        ($this->handler(self::entity(10.21)))(self::message(10.21));

        $this->assertSame([[
            '/ngsi-ld/v1/entities/'.self::T1.'/attrs',
            [
                'image' => ['type' => 'Property', 'value' => 'https://photos.example/10.210000,56.150000.jpg', 'observedAt' => '2026-11-20T12:00:00Z'],
                'imageDate' => ['type' => 'Property', 'value' => '2023-04-20', 'observedAt' => '2026-11-20T12:00:00Z'],
                'lookupAerialPhotos' => ['type' => 'Property', 'value' => 'found', 'observedAt' => '2026-11-20T12:00:00Z'],
            ],
        ]], $this->updates);
    }

    public function testItWritesOnlyTheAttributesThatTheMessageAsksFor(): void
    {
        $message = new LookupMessage(self::T1, 'https://context.example/toilet.jsonld', AerialPhotos::class, ['imageDate'], ['type' => 'Point', 'coordinates' => [10.21, 56.15]], null);

        ($this->handler(self::entity(10.21)))($message);

        $this->assertSame(['imageDate', 'lookupAerialPhotos'], array_keys($this->updates[0][1]));
    }

    public function testWithNoAnswerItWritesOnlyTheCheckProperty(): void
    {
        // AerialPhotos has no photo west of longitude 10.
        ($this->handler(self::entity(9.5)))(self::message(9.5));

        $this->assertSame([
            'lookupAerialPhotos' => ['type' => 'Property', 'value' => 'none', 'observedAt' => '2026-11-20T12:00:00Z'],
        ], $this->updates[0][1]);
    }

    public function testItIgnoresAnEntityThatDoesNotExist(): void
    {
        ($this->handler(null))(self::message(10.21));

        $this->assertSame([], $this->updates);
    }

    public function testItIgnoresAnEntityThatHasMoved(): void
    {
        ($this->handler(self::entity(10.22)))(self::message(10.21));

        $this->assertSame([], $this->updates);
    }

    public function testItIgnoresACheckThatAnotherMessageDid(): void
    {
        ($this->handler(self::entity(10.21, '2026-11-20T11:00:00Z')))(self::message(10.21, '2026-11-01T11:00:00Z'));

        $this->assertSame([], $this->updates);
    }

    public function testItDoesTheCheckIfTheCheckPropertyIsAsTheRunSawIt(): void
    {
        ($this->handler(self::entity(10.21, '2026-11-01T11:00:00Z')))(self::message(10.21, '2026-11-01T11:00:00Z'));

        $this->assertCount(1, $this->updates);
    }
}
