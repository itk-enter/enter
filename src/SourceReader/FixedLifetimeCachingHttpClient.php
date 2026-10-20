<?php

declare(strict_types=1);

namespace App\SourceReader;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Caches a successful GET response for a fixed lifetime.
 */
final class FixedLifetimeCachingHttpClient implements HttpClientInterface
{
    use DecoratorTrait;

    public function __construct(
        HttpClientInterface $client,
        #[Autowire(service: 'source_reader.cache')]
        private readonly CacheInterface $cache,
    ) {
        $this->client = $client;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (Request::METHOD_GET !== $method) {
            return $this->client->request($method, $url, $options);
        }

        $key = hash('xxh128', $url.'?'.http_build_query($options['query'] ?? []));

        $cached = $this->cache->get($key, function () use ($method, $url, $options): array {
            $response = $this->client->request($method, $url, $options);

            return [
                'body' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => $response->getHeaders(),
            ];
        });

        return new MockHttpClient(new MockResponse($cached['body'], [
            'http_code' => $cached['status'],
            'response_headers' => $cached['headers'],
        ]))->request($method, $url, $options);
    }
}
