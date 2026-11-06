<?php

declare(strict_types=1);

namespace App\SourceReader;

/**
 * The response of a feed, as the source sent it.
 *
 * The reader does not decode the body. Thus the import history can keep the
 * feed exactly as it was. Call toArray() to get the decoded JSON.
 */
final readonly class FeedResponse
{
    /**
     * @param string      $body        the body, as the source sent it
     * @param string|null $contentType the Content-Type header, if the source sent one
     */
    public function __construct(
        public string $body,
        public ?string $contentType,
    ) {
    }

    /**
     * Decodes the body as JSON.
     *
     * @return array<mixed>
     *
     * @throws \JsonException if the body is empty, is not JSON, or is not a JSON object or array
     */
    public function toArray(): array
    {
        if ('' === $this->body) {
            throw new \JsonException('The feed body is empty.');
        }

        $data = json_decode($this->body, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        if (!\is_array($data)) {
            throw new \JsonException(sprintf('The feed body is JSON of the type %s, not an object or an array.', get_debug_type($data)));
        }

        return $data;
    }
}
