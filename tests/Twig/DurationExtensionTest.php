<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\DurationExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Translation\LocaleSwitcher;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Tests the filter with the translations of the app, in English and in Danish.
 */
class DurationExtensionTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{int|null, string, string}>
     */
    public static function durations(): iterable
    {
        yield 'nothing' => [null, '', ''];
        yield 'zero' => [0, '0 s', '0 s'];
        yield 'seconds' => [59, '59 s', '59 s'];
        yield 'one minute' => [60, '1 min', '1 min'];
        yield 'minutes, rounded' => [150, '3 min', '3 min'];
        yield 'one hour' => [3600, '1 h', '1 t'];
        yield 'hours, rounded' => [5400, '2 h', '2 t'];
        yield 'hours up to two days' => [172_799, '48 h', '48 t'];
        yield 'two days' => [172_800, '2 d', '2 d'];
        yield 'days, rounded' => [216_000, '3 d', '3 d'];
    }

    #[DataProvider('durations')]
    public function testItWritesTheDurationInTheLargestUnitThatFits(?int $seconds, string $english, string $danish): void
    {
        $extension = new DurationExtension(static::getContainer()->get(TranslatorInterface::class));
        $locales = static::getContainer()->get(LocaleSwitcher::class);

        $this->assertSame($english, $locales->runWithLocale('en', static fn (): string => $extension->duration($seconds)));
        $this->assertSame($danish, $locales->runWithLocale('da', static fn (): string => $extension->duration($seconds)));
    }
}
