<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFilter;

/**
 * Gives the Twig filter "duration", which writes a number of seconds in the
 * largest unit that fits. The units come from the translation keys in
 * "duration".
 *
 * Example: {{ 7200|duration }} gives "2 h" in English and "2 t" in Danish.
 */
final readonly class DurationExtension
{
    private const int MINUTE = 60;

    private const int HOUR = 3600;

    private const int DAY = 86400;

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * Writes a number of seconds in seconds, minutes, hours or days. Up to
     * two days, the unit is hours, because "36 h" is more exact than "2 d".
     *
     * @return string the rounded value and the unit, for example "3 h". An empty string if $seconds is null.
     */
    #[AsTwigFilter('duration')]
    public function duration(?int $seconds): string
    {
        [$key, $count] = match (true) {
            null === $seconds => [null, 0],
            $seconds < self::MINUTE => ['duration.seconds', $seconds],
            $seconds < self::HOUR => ['duration.minutes', (int) round($seconds / self::MINUTE)],
            $seconds < 2 * self::DAY => ['duration.hours', (int) round($seconds / self::HOUR)],
            default => ['duration.days', (int) round($seconds / self::DAY)],
        };

        return null === $key ? '' : $this->translator->trans($key, ['%count%' => $count]);
    }
}
