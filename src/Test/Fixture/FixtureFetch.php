<?php

declare(strict_types=1);

namespace App\Test\Fixture;

/**
 * The result of one fixture fetch.
 */
final readonly class FixtureFetch
{
    /**
     * @param string $url       the live feed
     * @param string $filename  the fixture file
     * @param bool   $fromCache true if the content comes from the cache
     */
    public function __construct(
        public string $url,
        public string $filename,
        public bool $fromCache,
    ) {
    }
}
