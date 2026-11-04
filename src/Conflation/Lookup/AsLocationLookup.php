<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

/**
 * The configuration of a lookup: what it supplies and when to ask it again.
 *
 * The merged entity keeps the result of the last check in a property of its
 * own for each lookup, the check property. The NGSI-LD observedAt of the
 * property is the time of the check. The property exists also when the
 * service had no answer. Thus the run knows when to ask again.
 *
 * @see LocationLookupInterface
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsLocationLookup
{
    /**
     * The prefix of the name of the check property.
     */
    public const string CHECK_PROPERTY_PREFIX = 'lookup';

    /**
     * @param string        $id                the lookup's identifier, in lower case with hyphens, for example
     *                                         "addresses"
     * @param list<string>  $attributes        the attributes that the service can supply, named as in the result
     *                                         models of the merges
     * @param \DateInterval $recheckEmptyAfter the time to wait before the next check when the merged entity has no
     *                                         value from the service
     * @param \DateInterval $refreshAfter      the time to wait before the next check when the merged entity has a
     *                                         value from the service
     */
    public function __construct(
        public string $id,
        public array $attributes,
        public \DateInterval $recheckEmptyAfter,
        public \DateInterval $refreshAfter,
    ) {
        if ([] === $attributes) {
            throw new \InvalidArgumentException(\sprintf('Lookup %s declares no attributes.', $id));
        }
    }

    /**
     * The configuration a lookup class declares.
     *
     * @param class-string $class
     *
     * @throws \ReflectionException
     */
    public static function of(string $class): self
    {
        $attribute = new \ReflectionClass($class)->getAttributes(self::class)[0]
            ?? throw new \LogicException(\sprintf('Lookup %s declares no #[%s] attribute.', $class, self::class));

        return $attribute->newInstance();
    }

    /**
     * The name of the check property, for example "lookupAddresses" for the
     * id "addresses".
     */
    public function checkProperty(): string
    {
        return self::CHECK_PROPERTY_PREFIX.str_replace('-', '', ucwords($this->id, '-'));
    }
}
