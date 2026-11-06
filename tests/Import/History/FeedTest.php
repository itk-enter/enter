<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\Feed;
use PHPUnit\Framework\TestCase;

class FeedTest extends TestCase
{
    private const string BODY = '{"type": "FeatureCollection", "features": []}';

    public function testTheIdIsTheHashOfTheBody(): void
    {
        $feed = new Feed(self::BODY, 'application/json', new \DateTimeImmutable());

        $this->assertSame(hash('sha256', self::BODY), $feed->getHash());
        $this->assertSame(Feed::hashOf(self::BODY), $feed->getHash());
    }

    public function testTheSameBodyGivesTheSameId(): void
    {
        $first = new Feed(self::BODY, 'application/json', new \DateTimeImmutable('2026-11-05 01:00:00 UTC'));
        $second = new Feed(self::BODY, 'application/json', new \DateTimeImmutable('2026-11-06 01:00:00 UTC'));

        $this->assertSame($first->getHash(), $second->getHash());
    }

    public function testItKeepsTheBodyAndItsSize(): void
    {
        $feed = new Feed(self::BODY, null, new \DateTimeImmutable());

        $this->assertSame(self::BODY, $feed->getBody());
        $this->assertSame(\strlen(self::BODY), $feed->getSize());
        $this->assertNull($feed->getContentType());
    }
}
