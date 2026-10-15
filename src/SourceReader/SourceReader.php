<?php

namespace App\SourceReader;

use App\Source\SourceInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerTrait;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class SourceReader implements SourceReaderInterface
{
    use LoggerAwareTrait;
    use LoggerTrait;

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {
    }

    public function read(SourceInterface $source): iterable
    {
        // @todo Add some proper exception handling/logging.
        // @todo Cache request responses.
        $definition = $source->definition;

        return $this->client->request('GET', $definition->accessUrlBase(), [
            'query' => $definition->accessUrlQuery(),
        ])->toArray();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }
}
