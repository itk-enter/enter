<?php

declare(strict_types=1);

namespace App\Tests\Import\History;

use App\Import\History\EntityChange;
use App\Import\History\EntityChangeType;
use App\Import\History\SourceImport;
use PHPUnit\Framework\TestCase;

class EntityChangeTest extends TestCase
{
    public function testItKeepsTheImportTheEntityAndTheType(): void
    {
        $import = new SourceImport('mtm_spatialmaps-toilet-city', new \DateTimeImmutable('2026-11-07 01:00:00 UTC'));

        $change = new EntityChange($import, 'urn:ngsi-ld:PublicToilet:aarhus-toilet-city-3', EntityChangeType::Removed);

        $this->assertSame($import, $change->getSourceImport());
        $this->assertSame('urn:ngsi-ld:PublicToilet:aarhus-toilet-city-3', $change->getEntityId());
        $this->assertSame(EntityChangeType::Removed, $change->getType());
        $this->assertNull($change->getId());
    }
}
