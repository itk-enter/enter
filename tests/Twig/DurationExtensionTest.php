<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DurationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DurationExtensionTest extends TestCase
{
    /**
     * @return iterable<string, array{int|null, string}>
     */
    public static function durations(): iterable
    {
        yield 'nothing' => [null, ''];
        yield 'zero' => [0, '0 s'];
        yield 'seconds' => [59, '59 s'];
        yield 'one minute' => [60, '1 min'];
        yield 'minutes, rounded' => [150, '3 min'];
        yield 'one hour' => [3600, '1 h'];
        yield 'hours, rounded' => [5400, '2 h'];
        yield 'hours up to two days' => [172_799, '48 h'];
        yield 'two days' => [172_800, '2 d'];
        yield 'days, rounded' => [216_000, '3 d'];
    }

    #[DataProvider('durations')]
    public function testItWritesTheDurationInTheLargestUnitThatFits(?int $seconds, string $expected): void
    {
        $this->assertSame($expected, new DurationExtension()->duration($seconds));
    }
}
