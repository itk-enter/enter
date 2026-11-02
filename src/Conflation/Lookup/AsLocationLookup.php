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
     * The Messenger transport of the lookups that do not declare one of
     * their own.
     */
    public const string DEFAULT_TRANSPORT = 'lookups';

    /**
     * @param string       $id                the lookup's identifier, in lower case with hyphens, for example
     *                                        "aerial-photos"
     * @param list<string> $attributes        the attributes that the service can supply, named as in the result
     *                                        models of the merges
     * @param string       $recheckEmptyAfter an ISO 8601 duration: the time to wait before the next check when the
     *                                        merged entity has no value from the service
     * @param string       $refreshAfter      an ISO 8601 duration: the time to wait before the next check when the
     *                                        merged entity has a value from the service
     * @param string       $transport         the Messenger transport, that is the queue, that holds the messages of
     *                                        the lookup; the transport sets the request limit and the retries of the
     *                                        service in config/packages/messenger.yaml
     */
    public function __construct(
        public string $id,
        public array $attributes,
        public string $recheckEmptyAfter,
        public string $refreshAfter,
        public string $transport = self::DEFAULT_TRANSPORT,
    ) {
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Lookup id "%s" must be in lower case with hyphens.', $id));
        }

        if ([] === $attributes) {
            throw new \InvalidArgumentException(\sprintf('Lookup %s declares no attributes.', $id));
        }

        foreach (['recheckEmptyAfter' => $recheckEmptyAfter, 'refreshAfter' => $refreshAfter] as $name => $duration) {
            try {
                new \DateInterval($duration);
            } catch (\Exception) {
                throw new \InvalidArgumentException(\sprintf('Lookup %s gives "%s" as %s; it must be an ISO 8601 duration, for example "P7D".', $id, $duration, $name));
            }
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
     * Tests if a merged entity is due for a check.
     *
     * An entity that the lookup never checked is due. Otherwise, the entity is
     * due when refreshAfter has passed since the last check, if it has a
     * value from the lookup. If not, it is due when recheckEmptyAfter has
     * passed.
     *
     * @param string|null $checkedAt the observedAt of the check property, or null if there is none
     * @param bool        $hasValue  true if the entity has one or more values from the lookup
     */
    public function isDue(?string $checkedAt, bool $hasValue, \DateTimeImmutable $now): bool
    {
        if (null === $checkedAt) {
            return true;
        }

        try {
            $next = new \DateTimeImmutable($checkedAt)->add(new \DateInterval($hasValue ? $this->refreshAfter : $this->recheckEmptyAfter));
        } catch (\Exception) {
            return true;
        }

        return $next <= $now;
    }

    /**
     * The name of the check property, for example "lookupAerialPhotos" for
     * the id "aerial-photos".
     */
    public function checkProperty(): string
    {
        return self::CHECK_PROPERTY_PREFIX.str_replace('-', '', ucwords($this->id, '-'));
    }
}
