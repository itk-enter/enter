<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\DateTimeImmutableType;
use Doctrine\DBAL\Types\Exception\InvalidFormat;

/**
 * Writes and reads each datetime_immutable value in UTC.
 *
 * A DATETIME column has no time zone, and the default time zone of PHP can be
 * different in the web server and in the worker. Thus this type changes each
 * value to UTC before it writes it, and reads each value as UTC. UTC also has
 * no repeated hour, while local time repeats one hour each autumn.
 */
final class UtcDateTimeImmutableType extends DateTimeImmutableType
{
    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if ($value instanceof \DateTimeImmutable) {
            $value = $value->setTimezone(new \DateTimeZone('UTC'));
        }

        return parent::convertToDatabaseValue($value, $platform);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?\DateTimeImmutable
    {
        if (null === $value || $value instanceof \DateTimeImmutable) {
            return $value;
        }

        $dateTime = \DateTimeImmutable::createFromFormat($platform->getDateTimeFormatString(), $value, new \DateTimeZone('UTC'));

        if (false === $dateTime) {
            throw InvalidFormat::new($value, self::class, $platform->getDateTimeFormatString());
        }

        return $dateTime;
    }
}
