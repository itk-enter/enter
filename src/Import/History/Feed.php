<?php

declare(strict_types=1);

namespace App\Import\History;

use App\Doctrine\GzipBlobType;
use Doctrine\ORM\Mapping as ORM;

/**
 * The raw body of a feed, as an import read it.
 *
 * The SHA-256 hash of the body is the id. Thus the history keeps each body
 * one time only, also when several imports read the same body. For example,
 * two sources that share one feed, or a feed that did not change since the
 * last import. The database holds the body with gzip compression.
 */
#[ORM\Entity]
class Feed
{
    #[ORM\Id]
    #[ORM\Column(length: 64, options: ['fixed' => true])]
    private string $hash;

    /**
     * The size of the body, in bytes.
     */
    #[ORM\Column]
    private int $size;

    public function __construct(
        #[ORM\Column(type: GzipBlobType::NAME)]
        private string $body,
        #[ORM\Column(length: 255, nullable: true)]
        private ?string $contentType,
        #[ORM\Column]
        private \DateTimeImmutable $firstReadAt,
    ) {
        $this->hash = self::hashOf($body);
        $this->size = \strlen($body);
    }

    /**
     * Gets the id of the feed that has this body. Use it to find a feed in
     * the history before you make a new one.
     */
    public static function hashOf(string $body): string
    {
        return hash('sha256', $body);
    }

    public function getHash(): string
    {
        return $this->hash;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function getContentType(): ?string
    {
        return $this->contentType;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getFirstReadAt(): \DateTimeImmutable
    {
        return $this->firstReadAt;
    }
}
