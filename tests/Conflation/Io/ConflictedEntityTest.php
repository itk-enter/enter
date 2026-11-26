<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Io;

use App\Conflation\Io\ConflictedEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConflictedEntityTest extends TestCase
{
    /**
     * @return array<string, list<array{value: mixed, dataSet: string, entity: string}>>
     */
    private static function conflicts(): array
    {
        return [
            'isAccessibleForFree' => [
                ['value' => true, 'dataSet' => 'findtoilet-public-toilet', 'entity' => 'urn:ngsi-ld:PublicToilet:findtoilet-876'],
                ['value' => false, 'dataSet' => 'osm-public-toilet', 'entity' => 'urn:ngsi-ld:PublicToilet:osm-way-661096587'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function feature(array $properties, mixed $geometry = ['type' => 'Point', 'coordinates' => [10.21, 56.15]]): array
    {
        return ['type' => 'Feature', 'id' => 'urn:ngsi-ld:PublicToilet:merged-1', 'geometry' => $geometry, 'properties' => $properties];
    }

    public function testItReadsTheConflictsOfAFeature(): void
    {
        $entity = ConflictedEntity::fromFeature(self::feature([
            'name' => 'Toilet ved Hotel Marselis',
            'additionalInformation' => ['conflicts' => self::conflicts(), 'kind' => 'toilet'],
        ]));

        $this->assertNotNull($entity);
        $this->assertSame('urn:ngsi-ld:PublicToilet:merged-1', $entity->id);
        $this->assertSame('Toilet ved Hotel Marselis', $entity->title());
        $this->assertSame(['type' => 'Point', 'coordinates' => [10.21, 56.15]], $entity->geometry);
        $this->assertSame(self::conflicts(), $entity->conflicts);
    }

    public function testAnEntityWithoutANameHasItsIdAsTitle(): void
    {
        $entity = ConflictedEntity::fromFeature(self::feature(['name' => '', 'additionalInformation' => ['conflicts' => self::conflicts()]], geometry: null));

        $this->assertNotNull($entity);
        $this->assertSame('urn:ngsi-ld:PublicToilet:merged-1', $entity->title());
        $this->assertNull($entity->geometry);
    }

    /**
     * An attribute is only a conflict if two or more data sets state a value.
     * A stated value without a data set or an entity is not used.
     */
    public function testItLeavesOutTheAttributesWithFewerThanTwoValidValues(): void
    {
        $conflicts = self::conflicts();
        $conflicts['handwashing'] = [
            ['value' => true, 'dataSet' => 'findtoilet-public-toilet', 'entity' => 'urn:ngsi-ld:PublicToilet:findtoilet-876'],
            ['value' => false, 'dataSet' => 'osm-public-toilet'],
            'not a stated value',
        ];

        $entity = ConflictedEntity::fromFeature(self::feature(['additionalInformation' => ['conflicts' => $conflicts]]));

        $this->assertSame(['isAccessibleForFree'], array_keys($entity->conflicts ?? []));
    }

    /**
     * @param array<string, mixed> $feature
     */
    #[DataProvider('featuresWithoutAConflict')]
    public function testItGivesNullForAFeatureWithoutAConflict(array $feature): void
    {
        $this->assertNull(ConflictedEntity::fromFeature($feature));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function featuresWithoutAConflict(): iterable
    {
        yield 'no additional information' => [self::feature(['name' => 'Toilet'])];
        yield 'no conflicts' => [self::feature(['additionalInformation' => ['kind' => 'toilet']])];
        yield 'one value only' => [self::feature(['additionalInformation' => ['conflicts' => ['fee' => [self::conflicts()['isAccessibleForFree'][0]]]]])];
        yield 'no id' => [['type' => 'Feature', 'properties' => ['additionalInformation' => ['conflicts' => self::conflicts()]]]];
    }
}
