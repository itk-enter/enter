<?php

declare(strict_types=1);

namespace App\Ngsi;

/**
 * An attribute name, or a key in the value of an attribute, that NGSI-LD
 * reserves.
 *
 * The broker does not give an error for such a name. It drops the data, or
 * stores it under another name. Thus the mapping of a source has a defect,
 * and the import of the source must stop.
 */
final class ReservedNameException extends \LogicException
{
    public static function attributeName(string $name): self
    {
        return new self(\sprintf('NGSI-LD reserves the attribute name "%s". Use another name.', $name));
    }

    public static function keyInValue(string $attribute, string $key): self
    {
        return new self(\sprintf('The value of the attribute "%s" has the key "%s", which the broker drops. Use another key.', $attribute, $key));
    }
}
