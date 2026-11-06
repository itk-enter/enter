<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\GzipBlobType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Exception\InvalidType;
use PHPUnit\Framework\TestCase;

class GzipBlobTypeTest extends TestCase
{
    private const string BODY = '{"type": "FeatureCollection", "features": []}';

    public function testItWritesGzipData(): void
    {
        $value = new GzipBlobType()->convertToDatabaseValue(self::BODY, new MariaDBPlatform());

        $this->assertNotNull($value);
        $this->assertSame(self::BODY, gzdecode($value));
    }

    public function testItReadsAString(): void
    {
        $compressed = gzencode(self::BODY);

        $this->assertSame(self::BODY, new GzipBlobType()->convertToPHPValue($compressed, new MariaDBPlatform()));
    }

    public function testItReadsAStream(): void
    {
        $compressed = gzencode(self::BODY);
        $stream = fopen('php://memory', 'r+');
        $this->assertNotFalse($compressed);
        $this->assertNotFalse($stream);
        fwrite($stream, $compressed);

        $this->assertSame(self::BODY, new GzipBlobType()->convertToPHPValue($stream, new MariaDBPlatform()));
    }

    public function testItKeepsNull(): void
    {
        $type = new GzipBlobType();

        $this->assertNull($type->convertToDatabaseValue(null, new MariaDBPlatform()));
        $this->assertNull($type->convertToPHPValue(null, new MariaDBPlatform()));
    }

    public function testItRejectsAValueThatIsNotAString(): void
    {
        $this->expectException(InvalidType::class);

        new GzipBlobType()->convertToDatabaseValue(['features' => []], new MariaDBPlatform());
    }
}
