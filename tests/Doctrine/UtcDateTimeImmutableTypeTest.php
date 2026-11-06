<?php

declare(strict_types=1);

namespace App\Tests\Doctrine;

use App\Doctrine\UtcDateTimeImmutableType;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Types\Exception\InvalidFormat;
use PHPUnit\Framework\TestCase;

class UtcDateTimeImmutableTypeTest extends TestCase
{
    private string $defaultTimezone;

    protected function setUp(): void
    {
        $this->defaultTimezone = date_default_timezone_get();
        date_default_timezone_set('Europe/Copenhagen');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->defaultTimezone);
    }

    public function testItWritesALocalTimeInUtc(): void
    {
        $value = new \DateTimeImmutable('2026-11-06 01:00:00');

        $this->assertSame('2026-11-06 00:00:00', new UtcDateTimeImmutableType()->convertToDatabaseValue($value, new MariaDBPlatform()));
    }

    public function testItReadsAValueAsUtc(): void
    {
        $value = new UtcDateTimeImmutableType()->convertToPHPValue('2026-11-06 00:00:00', new MariaDBPlatform());

        $this->assertNotNull($value);
        $this->assertSame('2026-11-06T00:00:00+00:00', $value->format(\DATE_ATOM));
        $this->assertSame('UTC', $value->getTimezone()->getName());
    }

    public function testTheRepeatedLocalHourInAutumnGivesTwoDifferentValues(): void
    {
        $copenhagen = new \DateTimeZone('Europe/Copenhagen');
        $summerTime = new \DateTimeImmutable('2026-10-25 00:30:00 UTC')->setTimezone($copenhagen);
        $winterTime = new \DateTimeImmutable('2026-10-25 01:30:00 UTC')->setTimezone($copenhagen);
        $type = new UtcDateTimeImmutableType();

        $this->assertSame($summerTime->format('H:i'), $winterTime->format('H:i'));
        $this->assertSame('2026-10-25 00:30:00', $type->convertToDatabaseValue($summerTime, new MariaDBPlatform()));
        $this->assertSame('2026-10-25 01:30:00', $type->convertToDatabaseValue($winterTime, new MariaDBPlatform()));
    }

    public function testItKeepsNull(): void
    {
        $type = new UtcDateTimeImmutableType();

        $this->assertNull($type->convertToDatabaseValue(null, new MariaDBPlatform()));
        $this->assertNull($type->convertToPHPValue(null, new MariaDBPlatform()));
    }

    public function testItRejectsAValueInAnotherFormat(): void
    {
        $this->expectException(InvalidFormat::class);

        new UtcDateTimeImmutableType()->convertToPHPValue('6 November 2026', new MariaDBPlatform());
    }
}
