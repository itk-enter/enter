<?php

declare(strict_types=1);

namespace App\Tests\Lookup\Datafordeler;

use App\Lookup\Datafordeler\DatafordelerClient;
use App\Lookup\Datafordeler\RedactApiKey;
use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class DatafordelerClientTest extends TestCase
{
    private static function client(MockResponse $response): DatafordelerClient
    {
        return new DatafordelerClient(new MockHttpClient($response, 'https://graphql.datafordeler.dk/'));
    }

    public function testTheClientGivesTheDataOfTheResponse(): void
    {
        $data = self::client(new MockResponse('{"data":{"DAR_Husnummer":{"nodes":[]}}}'))->query('{ x }');

        $this->assertSame(['DAR_Husnummer' => ['nodes' => []]], $data);
    }

    public function testGraphqlErrorsMakeAnException(): void
    {
        $this->expectExceptionMessage('Datafordeleren returned errors: The argument `virkningstid` is required.');

        self::client(new MockResponse('{"errors":[{"message":"The argument `virkningstid` is required."}]}'))->query('{ x }');
    }

    public function testAnHttpErrorMakesAnExceptionWithoutTheUrl(): void
    {
        try {
            self::client(new MockResponse('', ['http_code' => 401]))->query('{ x }');
            $this->fail('No exception.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Datafordeleren returned HTTP 401.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function testTheApiKeyIsRemovedFromTheLogsOfTheHttpClient(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'http_client', Level::Info, 'Request: "POST https://graphql.datafordeler.dk/flexibleCurrent/v1?apiKey=secret-key"');

        $this->assertSame('Request: "POST https://graphql.datafordeler.dk/flexibleCurrent/v1?apiKey=***"', new RedactApiKey()($record)->message);
    }

    public function testTheApiKeyIsRemovedFromTheContextOfTheLogsOfTheHttpClient(): void
    {
        $record = new LogRecord(new \DateTimeImmutable(), 'http_client', Level::Info, 'Response: "{http_code} {url}"', [
            'http_code' => 200,
            'url' => 'https://graphql.datafordeler.dk/flexibleCurrent/v1?apiKey=secret-key',
        ]);

        $this->assertSame([
            'http_code' => 200,
            'url' => 'https://graphql.datafordeler.dk/flexibleCurrent/v1?apiKey=***',
        ], new RedactApiKey()($record)->context);
    }
}
