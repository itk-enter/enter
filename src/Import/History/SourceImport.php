<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One import of one source: when it started and finished, the feed that it
 * read and the result.
 *
 * An import starts as running. Then it gets the feed that it read, if the
 * read was successful. At the end, call succeed() or fail() one time. After
 * that, the import does not change.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['source_id', 'started_at'])]
class SourceImport
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(length: 16, enumType: ImportStatus::class)]
    private ImportStatus $status = ImportStatus::Running;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(name: 'feed_hash', referencedColumnName: 'hash')]
    private ?Feed $feed = null;

    /**
     * The SHA-256 hash of the entities that the import upserted. If two
     * imports of a source have the same hash, the data did not change.
     */
    #[ORM\Column(length: 64, nullable: true, options: ['fixed' => true])]
    private ?string $entityHash = null;

    #[ORM\Column(nullable: true)]
    private ?int $upserted = null;

    #[ORM\Column(nullable: true)]
    private ?int $rejected = null;

    #[ORM\Column(nullable: true)]
    private ?int $deleted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $failure = null;

    public function __construct(
        #[ORM\Column(length: 255)]
        private string $sourceId,
        #[ORM\Column]
        private \DateTimeImmutable $startedAt,
    ) {
    }

    /**
     * Records the feed that the import read.
     */
    public function recordFeed(Feed $feed): void
    {
        $this->assertRunning();
        $this->feed = $feed;
    }

    /**
     * Records that the import upserted the entities and deleted the stale
     * entities.
     *
     * @param string $entityHash the SHA-256 hash of the upserted entities
     * @param int    $upserted   the number of upserted entities
     * @param int    $rejected   the number of entities that the broker rejected
     * @param int    $deleted    the number of deleted stale entities
     */
    public function succeed(\DateTimeImmutable $finishedAt, string $entityHash, int $upserted, int $rejected, int $deleted): void
    {
        $this->assertRunning();
        $this->status = ImportStatus::Succeeded;
        $this->finishedAt = $finishedAt;
        $this->entityHash = $entityHash;
        $this->upserted = $upserted;
        $this->rejected = $rejected;
        $this->deleted = $deleted;
    }

    /**
     * Records that the import stopped because of an error.
     *
     * @param string $failure the error, for example the class and the message
     *                        of the exception
     */
    public function fail(\DateTimeImmutable $finishedAt, string $failure): void
    {
        $this->assertRunning();
        $this->status = ImportStatus::Failed;
        $this->finishedAt = $finishedAt;
        $this->failure = $failure;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getStatus(): ImportStatus
    {
        return $this->status;
    }

    public function getFeed(): ?Feed
    {
        return $this->feed;
    }

    public function getEntityHash(): ?string
    {
        return $this->entityHash;
    }

    public function getUpserted(): ?int
    {
        return $this->upserted;
    }

    public function getRejected(): ?int
    {
        return $this->rejected;
    }

    public function getDeleted(): ?int
    {
        return $this->deleted;
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    private function assertRunning(): void
    {
        if (ImportStatus::Running !== $this->status) {
            throw new \LogicException(sprintf('The import of %s is %s and cannot change.', $this->sourceId, $this->status->value));
        }
    }
}
