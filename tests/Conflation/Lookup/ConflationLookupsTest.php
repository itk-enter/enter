<?php

declare(strict_types=1);

namespace App\Tests\Conflation\Lookup;

use App\Conflation\Conflation;
use App\Tests\Conflation\Fixtures\AerialPhotos;
use App\Tests\Conflation\Fixtures\GroupedByThing;
use App\Tests\Conflation\Fixtures\StreetPhotos;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The "lookups" option of the #[Conflation] attribute.
 */
class ConflationLookupsTest extends TestCase
{
    /**
     * @param array<class-string, list<string>> $lookups
     */
    private static function conflation(array $lookups): Conflation
    {
        return new Conflation(
            id: 'merged-toilets',
            title: 'Merged toilets',
            description: 'Toilets merged from several data sets.',
            resultModel: 'ResultModel',
            defining: ['ModelA'],
            mergeByLocation: new GroupedByThing(5.0),
            lookups: $lookups,
        );
    }

    public function testAMergeTakesSomeAttributesOfALookup(): void
    {
        $this->assertSame([AerialPhotos::class => ['image']], self::conflation([AerialPhotos::class => ['image']])->lookups);
    }

    /**
     * @param array<class-string, list<string>> $lookups
     */
    #[DataProvider('invalidLookups')]
    public function testItRejectsInvalidLookups(array $lookups, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        self::conflation($lookups);
    }

    /**
     * @return iterable<string, array{array<class-string, list<string>>, string}>
     */
    public static function invalidLookups(): iterable
    {
        yield 'not a lookup' => [[GroupedByThing::class => ['image']], 'must implement'];
        yield 'an attribute the lookup does not declare' => [[AerialPhotos::class => ['image', 'address']], 'not "address"'];
        yield 'no attributes' => [[AerialPhotos::class => []], 'must take one or more'];
        yield 'one attribute from two lookups' => [
            [AerialPhotos::class => ['image'], StreetPhotos::class => ['image']],
            'takes image from both lookup aerial-photos and lookup street-photos',
        ];
    }
}
