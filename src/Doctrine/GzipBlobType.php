<?php

declare(strict_types=1);

namespace App\Doctrine;

use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\BlobType;
use Doctrine\DBAL\Types\Exception\InvalidType;
use Doctrine\DBAL\Types\Exception\ValueNotConvertible;

/**
 * Keeps a string in a BLOB column with gzip compression.
 *
 * The entity holds the string as it was before compression. Use this type
 * for a large text, such as the body of a feed.
 */
final class GzipBlobType extends BlobType
{
    public const string NAME = 'gzip_blob';

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw InvalidType::new($value, self::class, ['null', 'string']);
        }

        $compressed = gzencode($value);
        if (false === $compressed) {
            throw ValueNotConvertible::new($value, self::class, 'The compression failed.');
        }

        return $compressed;
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value) {
            return null;
        }

        $compressed = \is_resource($value) ? stream_get_contents($value, null, 0) : $value;
        $decompressed = \is_string($compressed) ? gzdecode($compressed) : false;
        if (false === $decompressed) {
            throw ValueNotConvertible::new($value, self::class, 'The value is not gzip data.');
        }

        return $decompressed;
    }
}
