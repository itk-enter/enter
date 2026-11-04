<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Lookup\AsLocationLookup;
use App\Tests\Conflation\Fixtures\NearestAddress;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AsLocationLookupTest extends TestCase
{
    public function testItReadsTheConfigurationALookupClassDeclares(): void
    {
        $lookup = AsLocationLookup::of(NearestAddress::class);

        $this->assertSame('addresses', $lookup->id);
        $this->assertSame(['address', 'postalCode'], $lookup->attributes);
        $this->assertEquals(new \DateInterval('P7D'), $lookup->recheckEmptyAfter);
        $this->assertEquals(new \DateInterval('P90D'), $lookup->refreshAfter);
        $this->assertSame('lookupAddresses', $lookup->checkProperty());
    }

    public function testItRequiresTheAttribute(): void
    {
        $this->expectException(\LogicException::class);

        AsLocationLookup::of(self::class);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    #[DataProvider('invalidConfigurations')]
    public function testItRejectsAnInvalidConfiguration(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new AsLocationLookup(...[
            'id' => 'addresses',
            'attributes' => ['address'],
            'recheckEmptyAfter' => new \DateInterval('P7D'),
            'refreshAfter' => new \DateInterval('P90D'),
            ...$arguments,
        ]);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'id in camel case' => [['id' => 'nearestAddress'], 'in lower case with hyphens'];
        yield 'no attributes' => [['attributes' => []], 'declares no attributes'];
    }
}
