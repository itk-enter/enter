<?php

declare(strict_types=1);

namespace App\Tests\SourceReader;

use App\SourceReader\FeedResponse;
use PHPUnit\Framework\TestCase;

class FeedResponseTest extends TestCase
{
    public function testItKeepsTheBodyAsTheSourceSentIt(): void
    {
        $body = "{ \"elements\": [1] }\n";
        $response = new FeedResponse($body, 'application/json');

        $this->assertSame($body, $response->body);
        $this->assertSame('application/json', $response->contentType);
    }

    public function testItDecodesTheBody(): void
    {
        $response = new FeedResponse('{"type": "FeatureCollection", "features": [{"id": 7}]}', null);

        $this->assertSame(['type' => 'FeatureCollection', 'features' => [['id' => 7]]], $response->toArray());
    }

    public function testItKeepsABigIntegerAsAString(): void
    {
        $response = new FeedResponse('{"id": 123456789012345678901234567890}', null);

        $this->assertSame(['id' => '123456789012345678901234567890'], $response->toArray());
    }

    public function testItRejectsAnEmptyBody(): void
    {
        $this->expectException(\JsonException::class);

        new FeedResponse('', null)->toArray();
    }

    public function testItRejectsABodyThatIsNotJson(): void
    {
        $this->expectException(\JsonException::class);

        new FeedResponse('<html>Service unavailable</html>', 'text/html')->toArray();
    }

    public function testItRejectsJsonThatIsNotAnObjectOrAnArray(): void
    {
        $this->expectException(\JsonException::class);

        new FeedResponse('"ok"', 'application/json')->toArray();
    }
}
