<?php

declare(strict_types=1);

namespace App\Tests\Templates;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Makes sure that the pages use the Twig components for the common elements.
 *
 * A template outside templates/components/ must not have a raw button,
 * heading, form field or alert, or a class of a button or a tag of the
 * design system. Use the component in templates/components/. If an element
 * must stay raw, put {# raw: <reason> #} on the same line or on the line
 * above.
 */
class RawElementsTest extends TestCase
{
    private const string RAW = '~<(button|h[1-6]|label|input|select|textarea)\b|role="alert"|class="[^"]*\bitkaarhus-(btn|tag)\b~';

    private const string MARKER = '~\{#\s*raw:\s*\S~';

    /**
     * @return iterable<string, array{string}>
     */
    public static function templates(): iterable
    {
        $finder = Finder::create()
            ->files()
            ->in(\dirname(__DIR__, 2).'/templates')
            ->name('*.twig')
            ->notPath('components');

        foreach ($finder as $file) {
            yield $file->getRelativePathname() => [$file->getPathname()];
        }
    }

    #[DataProvider('templates')]
    public function testTheTemplateUsesTheComponents(string $path): void
    {
        $lines = file($path) ?: [];
        $found = [];

        foreach ($lines as $index => $line) {
            // Twig comments are not output. A marker is also a comment, so read it first.
            $marked = 1 === preg_match(self::MARKER, $line) || 1 === preg_match(self::MARKER, $lines[$index - 1] ?? '');
            $code = preg_replace('~\{#.*?#\}~', '', $line) ?? $line;

            if (!$marked && 1 === preg_match(self::RAW, $code)) {
                $found[] = \sprintf('line %d: %s', $index + 1, trim($line));
            }
        }

        $this->assertSame([], $found, 'Use a <twig:…> component, or add {# raw: <reason> #}.');
    }

    public function testItFindsARawElement(): void
    {
        $this->assertMatchesRegularExpression(self::RAW, '<h2 class="h4">Imports</h2>');
        $this->assertMatchesRegularExpression(self::RAW, '<a class="itkaarhus-btn itkaarhus-btn--sm" href="/">');
        $this->assertDoesNotMatchRegularExpression(self::RAW, '<twig:Heading level="2">Imports</twig:Heading>');
        $this->assertDoesNotMatchRegularExpression(self::RAW, '<header class="page-header">');
        $this->assertMatchesRegularExpression(self::MARKER, '{# raw: the map script reads this button #}');
    }
}
