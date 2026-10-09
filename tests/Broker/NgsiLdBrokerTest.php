<?php

declare(strict_types=1);

namespace App\Tests\Broker;

use App\Broker\NgsiLdBroker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The broker hands out and takes at most a thousand entities per request,
 * so a source of more must be read, written and swept in pages.
 */
class NgsiLdBrokerTest extends TestCase
{
    /** @var list<array{string, string, array<string, mixed>}> */
    private array $requests = [];

    public function testItReadsIdsPageByPageUntilAPageComesBackShort(): void
    {
        $broker = $this->broker([
            new MockResponse(json_encode($this->entities(0, 1000), \JSON_THROW_ON_ERROR)),
            new MockResponse(json_encode($this->entities(1000, 1), \JSON_THROW_ON_ERROR)),
        ]);

        $ids = $broker->idsWhereAttributeEquals('Bench', 'https://example.com/context.jsonld', 'sourceId', 'osm-bench');

        $this->assertCount(1001, $ids);
        $this->assertSame('urn:ngsi-ld:Bench:1000', $ids[1000]);
        $this->assertSame(['0', '1000'], array_map(static function (array $request): string {
            parse_str((string) parse_url($request[1], \PHP_URL_QUERY), $query);

            return (string) $query['offset'];
        }, $this->requests));
    }

    /**
     * Asking for no attribute would match no entity, as the attributes asked
     * for also filter, so the one matched on is asked for.
     */
    public function testItAsksOnlyForTheAttributeItMatchesOn(): void
    {
        $this->broker([new MockResponse('[]')])->idsWhereAttributeEquals('Bench', 'https://example.com/context.jsonld', 'sourceId', 'osm-bench');

        parse_str((string) parse_url($this->requests[0][1], \PHP_URL_QUERY), $query);
        $this->assertSame('sourceId=="osm-bench"', $query['q']);
        $this->assertSame('sourceId', $query['attrs']);
    }

    public function testItDeletesInBatchesTheBrokerAccepts(): void
    {
        $ids = array_column($this->entities(0, 1001), 'id');

        $this->broker([new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT]), new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT])])->delete($ids);

        $this->assertCount(2, $this->requests);
        $this->assertCount(1000, json_decode((string) $this->requests[0][2]['body'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame(['urn:ngsi-ld:Bench:1000'], json_decode((string) $this->requests[1][2]['body'], true, flags: \JSON_THROW_ON_ERROR));
    }

    public function testItUpsertsInBatchesTheBrokerAccepts(): void
    {
        $entities = $this->entities(0, 1001);

        $result = $this->broker([new MockResponse('', ['http_code' => Response::HTTP_CREATED]), new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT])])->upsert($entities);

        $this->assertCount(2, $this->requests);
        $this->assertCount(1000, json_decode((string) $this->requests[0][2]['body'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame([$entities[1000]], json_decode((string) $this->requests[1][2]['body'], true, flags: \JSON_THROW_ON_ERROR));
        $this->assertSame(Response::HTTP_NO_CONTENT, $result->status);
        $this->assertSame([], $result->rejected);
    }

    /**
     * A later batch that goes through must not hide the entities an earlier
     * one had rejected.
     */
    public function testItReportsTheEntitiesABatchOfTheUpsertRejected(): void
    {
        $partly = json_encode([
            'success' => ['urn:ngsi-ld:Bench:0'],
            'errors' => [
                ['entityId' => 'urn:ngsi-ld:Bench:1', 'error' => ['type' => 'https://uri.etsi.org/ngsi-ld/errors/BadRequestData', 'title' => 'Bad Request Data', 'detail' => 'Invalid location']],
                ['entityId' => 'urn:ngsi-ld:Bench:2', 'error' => ['type' => 'https://uri.etsi.org/ngsi-ld/errors/BadRequestData']],
            ],
        ], \JSON_THROW_ON_ERROR);

        $result = $this->broker([new MockResponse($partly, ['http_code' => Response::HTTP_MULTI_STATUS]), new MockResponse('', ['http_code' => Response::HTTP_NO_CONTENT])])->upsert($this->entities(0, 1001));

        $this->assertSame(Response::HTTP_MULTI_STATUS, $result->status);
        $this->assertSame([
            'urn:ngsi-ld:Bench:1' => 'Invalid location',
            'urn:ngsi-ld:Bench:2' => 'https://uri.etsi.org/ngsi-ld/errors/BadRequestData',
        ], $result->rejected);
    }

    public function testItFailsWhenTheBrokerRefusesABatchOfTheUpsert(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->broker([new MockResponse('', ['http_code' => Response::HTTP_CREATED]), new MockResponse('', ['http_code' => Response::HTTP_BAD_REQUEST])])->upsert($this->entities(0, 1001));
    }

    public function testItSendsNothingToUpsertNothing(): void
    {
        $this->assertSame(Response::HTTP_NO_CONTENT, $this->broker([])->upsert([])->status);
        $this->assertSame([], $this->requests);
    }

    public function testItSendsNothingToDeleteNothing(): void
    {
        $this->broker([])->delete([]);

        $this->assertSame([], $this->requests);
    }

    /**
     * Ids the broker could not delete are listed in a 207; one already gone
     * is as good as deleted.
     */
    public function testItAcceptsAPartlyFailedDelete(): void
    {
        $this->broker([new MockResponse('{"success": [], "errors": []}', ['http_code' => Response::HTTP_MULTI_STATUS])])->delete(['urn:ngsi-ld:Bench:1']);

        $this->assertCount(1, $this->requests);
    }

    public function testItFailsWhenTheBrokerRefusesTheDelete(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->broker([new MockResponse('', ['http_code' => Response::HTTP_BAD_REQUEST])])->delete(['urn:ngsi-ld:Bench:1']);
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function broker(array $responses): NgsiLdBroker
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [$method, $url, $options];

            return array_shift($responses) ?? throw new \LogicException('No response left.');
        });

        return new NgsiLdBroker($client, 'http://broker.example');
    }

    /**
     * @return list<array{id: string, type: string}>
     */
    private function entities(int $from, int $count): array
    {
        return array_map(
            static fn (int $n): array => ['id' => 'urn:ngsi-ld:Bench:'.$n, 'type' => 'Bench'],
            range($from, $from + $count - 1),
        );
    }
}
