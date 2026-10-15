<?php

declare(strict_types=1);

namespace App\Test\Fixture;

/**
 * What one fixture fetch did.
 */
final readonly class FixtureFetch
{
    /**
     * @param string $url       the live feed
     * @param string $filename  the fixture written
     * @param bool   $fromCache whether the content was a kept response rather than fetched
     */
    public function __construct(
        public string $url,
        public string $filename,
        public bool $fromCache,
    ) {
    }
}
