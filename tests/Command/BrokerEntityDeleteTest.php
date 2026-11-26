<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Broker\NgsiLdBroker;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests the console output of app:broker:entity:delete.
 *
 * The broker is a mock that keeps its entities in $entities. A delete
 * removes them, except the ids in $kept.
 */
class BrokerEntityDeleteTest extends KernelTestCase
{
    private const string BENCH = 'https://smartdatamodels.org/dataModel.UrbanMobility/Bench';
    private const string TOILET = 'https://smartdatamodels.org/dataModel.PointOfInterest/PublicToilet';

    /**
     * The ids of the entities in the broker, by type.
     *
     * @var array<string, list<string>>
     */
    private array $entities = [];

    /**
     * The ids that the broker does not delete.
     *
     * @var list<string>
     */
    private array $kept = [];

    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->set(NgsiLdBroker::class, new NgsiLdBroker(new MockHttpClient($this->respond(...)), 'http://broker.example'));
    }

    public function testItDeletesTheEntitiesOfTheTypesThatTheArgumentNames(): void
    {
        $this->entities = [self::BENCH => ['urn:ngsi-ld:Bench:1', 'urn:ngsi-ld:Bench:2'], self::TOILET => ['urn:ngsi-ld:PublicToilet:1']];

        $result = self::runCommand('app:broker:entity:delete', ['type' => [self::BENCH]]);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('The command deletes 2 '.self::BENCH.' entities …', $result->getDisplay());
        $this->assertSame([self::TOILET => ['urn:ngsi-ld:PublicToilet:1']], $this->remaining());
    }

    public function testTheAllOptionDeletesTheEntitiesOfEachTypeInTheBroker(): void
    {
        $this->entities = [self::BENCH => ['urn:ngsi-ld:Bench:1'], self::TOILET => ['urn:ngsi-ld:PublicToilet:1']];

        $result = self::runCommand('app:broker:entity:delete', ['--all' => true]);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('The command deletes one '.self::TOILET.' entity …', $result->getDisplay());
        $this->assertSame([], $this->remaining());
    }

    public function testItSaysIfTheBrokerHasNoEntitiesOfAType(): void
    {
        $result = self::runCommand('app:broker:entity:delete', ['type' => [self::BENCH]]);

        $this->assertCommandIsSuccessful($result);
        $this->assertStringContainsString('The broker has no '.self::BENCH.' entities.', $result->getDisplay());
    }

    /**
     * If the broker keeps an entity, the next read gives it again. Thus the
     * command stops with a failure, so that it does not loop.
     */
    public function testItFailsIfTheBrokerDoesNotDeleteAnEntity(): void
    {
        $this->entities = [self::BENCH => ['urn:ngsi-ld:Bench:1', 'urn:ngsi-ld:Bench:2']];
        $this->kept = ['urn:ngsi-ld:Bench:2'];

        $result = self::runCommand('app:broker:entity:delete', ['type' => [self::BENCH]], normalizers: [self::oneLine(...)]);

        $this->assertCommandFailed($result);
        $this->assertStringContainsString('The broker did not delete 1 '.self::BENCH.' entities. urn:ngsi-ld:Bench:2: The entity is locked', $result->getDisplay());
    }

    public function testItNeedsATypeOrTheAllOption(): void
    {
        $this->expectExceptionMessage('The entity types are missing');

        self::runCommand('app:broker:entity:delete');
    }

    /**
     * Gets the types that still have entities in the broker, with their ids.
     *
     * @return array<string, list<string>>
     */
    private function remaining(): array
    {
        return array_filter($this->entities);
    }

    /**
     * Answers a request to the broker from $entities.
     *
     * @param array<string, mixed> $options
     */
    private function respond(string $method, string $url, array $options): MockResponse
    {
        if (str_ends_with((string) parse_url($url, \PHP_URL_PATH), '/types')) {
            return new MockResponse(json_encode(['typeList' => array_keys(array_filter($this->entities))], \JSON_THROW_ON_ERROR));
        }

        if ('GET' === $method) {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $ids = $this->entities[$query['type']] ?? [];

            return new MockResponse(json_encode(array_map(static fn (string $id): array => ['id' => $id], $ids), \JSON_THROW_ON_ERROR));
        }

        $ids = json_decode((string) $options['body'], true, flags: \JSON_THROW_ON_ERROR);
        $kept = array_values(array_intersect($ids, $this->kept));
        foreach ($this->entities as $type => $entityIds) {
            $this->entities[$type] = array_values(array_diff($entityIds, array_diff($ids, $kept)));
        }

        if ([] === $kept) {
            return new MockResponse('', ['http_code' => 204]);
        }

        $errors = array_map(static fn (string $id): array => ['entityId' => $id, 'error' => ['title' => 'The entity is locked']], $kept);

        return new MockResponse(json_encode(['success' => array_values(array_diff($ids, $kept)), 'errors' => $errors], \JSON_THROW_ON_ERROR), ['http_code' => 207]);
    }

    /**
     * Puts the output on one line, because the console breaks a long message into lines.
     */
    private static function oneLine(string $display): string
    {
        return (string) preg_replace('/\s+/', ' ', $display);
    }
}
