<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Attribute\AsTwigFilter;

/**
 * Gives the Twig filter "duration", which writes a number of seconds in the
 * largest unit that fits.
 *
 * Example: {{ 7200|duration }} gives "2 h".
 */
final class DurationExtension
{
    private const int MINUTE = 60;

    private const int HOUR = 3600;

    private const int DAY = 86400;

    /**
     * Writes a number of seconds in seconds, minutes, hours or days. Up to
     * two days, the unit is hours, because "36 h" is more exact than "2 d".
     *
     * @return string the rounded value and the unit, for example "3 h". An empty string if $seconds is null.
     */
    #[AsTwigFilter('duration')]
    public function duration(?int $seconds): string
    {
        return match (true) {
            null === $seconds => '',
            $seconds < self::MINUTE => $seconds.' s',
            $seconds < self::HOUR => round($seconds / self::MINUTE).' min',
            $seconds < 2 * self::DAY => round($seconds / self::HOUR).' h',
            default => round($seconds / self::DAY).' d',
        };
    }
}
