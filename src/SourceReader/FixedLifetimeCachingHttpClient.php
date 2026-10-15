<?php

declare(strict_types=1);

namespace App\SourceReader;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpClient\DecoratorTrait;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Keeps a successful GET for the cache pool's lifetime, whatever the
 * server's cache headers say.
 *
 * Sources that split one feed between them are imported one by one, in
 * processes of their own, and this lets them share one fetch. Symfony's
 * CachingHttpClient cannot: it obeys the cache headers, and the feeds send
 * no-cache or none at all.
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
        if ('GET' !== $method) {
            return $this->client->request($method, $url, $options);
        }

        // Key the cache on a hash of the URL, as a cache key may not hold
        // characters such as "/", ":" or "(" that a URL does.
        $key = hash('xxh128', $url.'?'.http_build_query($options['query'] ?? []));

        $cached = $this->cache->get($key, function () use ($method, $url, $options): array {
            $response = $this->client->request($method, $url, $options);

            // getContent() throws on an error status, so a failed fetch is
            // never kept.
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
