<?php

declare(strict_types=1);

namespace App\Import\History;

use Doctrine\ORM\Mapping as ORM;

/**
 * One entity that an import added to the broker or removed from it.
 *
 * An entity that stays in the broker gets no row, also if its values changed.
 * Thus the table tells when an entity came or went, and the import that made
 * the change.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['entity_id'])]
class EntityChange
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function __construct(
        #[ORM\ManyToOne]
        #[ORM\JoinColumn(nullable: false)]
        private SourceImport $sourceImport,
        #[ORM\Column(length: 255)]
        private string $entityId,
        #[ORM\Column(length: 16, enumType: EntityChangeType::class)]
        private EntityChangeType $type,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSourceImport(): SourceImport
    {
        return $this->sourceImport;
    }

    public function getEntityId(): string
    {
        return $this->entityId;
    }

    public function getType(): EntityChangeType
    {
        return $this->type;
    }
}
