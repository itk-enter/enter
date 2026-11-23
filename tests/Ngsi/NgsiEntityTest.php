<?php

declare(strict_types=1);

namespace App\Tests\Ngsi;

use App\Ngsi\NgsiEntity;
use App\Ngsi\ReservedNameException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class NgsiEntityTest extends TestCase
{
    public function testAPropertyCanTellWhenItsValueWasObserved(): void
    {
        $entity = new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')
            ->setProperty('seats', 3, observedAt: '2026-11-23T12:00:00Z');

        $this->assertSame(['type' => 'Property', 'value' => 3, 'observedAt' => '2026-11-23T12:00:00Z'], $entity->attribute('seats'));
    }

    /**
     * The broker gives no error for a reserved name. It drops the data, or
     * stores it under another name. Thus the entity rejects the name.
     */
    #[DataProvider('reservedAttributeNames')]
    public function testItRejectsAReservedAttributeName(string $name): void
    {
        $this->expectException(ReservedNameException::class);
        $this->expectExceptionMessage(\sprintf('NGSI-LD reserves the attribute name "%s".', $name));

        new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')->setProperty($name, 'value');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function reservedAttributeNames(): iterable
    {
        foreach (['id', 'type', '@context', 'createdAt', 'modifiedAt', 'deletedAt', 'observedAt', 'datasetId', 'instanceId', 'unitCode', 'objects', 'values'] as $name) {
            yield $name => [$name];
        }
    }

    public function testItRejectsAReservedNameForAGeoProperty(): void
    {
        $this->expectException(ReservedNameException::class);

        new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')->geoProperty('type', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);
    }

    /**
     * The broker also drops createdAt and modifiedAt as keys in a value, at
     * any depth.
     */
    public function testItRejectsAKeyInAValueThatTheBrokerDrops(): void
    {
        $this->expectException(ReservedNameException::class);
        $this->expectExceptionMessage('The value of the attribute "additionalInformation" has the key "modifiedAt"');

        new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')->additionalInformation(['register' => ['modifiedAt' => '2026-11-23']]);
    }

    public function testItAcceptsTheNamesThatTheSourcesUseInstead(): void
    {
        $entity = new NgsiEntity('urn:ngsi-ld:Bench:1', 'Bench')
            ->additionalInformation(['registeredAt' => '2017-01-23', 'updatedAt' => '2021-10-06'])
            ->geoProperty('location', ['type' => 'Point', 'coordinates' => [10.21, 56.15]]);

        $this->assertSame(['registeredAt' => '2017-01-23', 'updatedAt' => '2021-10-06'], $entity->attribute('additionalInformation')['value'] ?? null);
        $this->assertSame('GeoProperty', $entity->attribute('location')['type'] ?? null);
    }
}
