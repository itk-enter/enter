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
 * When the run sends a message, it adds the sub-property requestedAt to the
 * check property. While this mark is younger than requestTimeout, the run
 * does not send the check again. The answer of the worker replaces the check
 * property, and so removes the mark.
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
     * The sub-property of the check property that marks a requested check.
     */
    public const string REQUESTED_AT = 'requestedAt';

    /**
     * The format of the times in the check property: UTC, ISO 8601.
     */
    public const string TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * @param string       $id                the lookup's identifier, in lower case with hyphens, for example
     *                                        "aerial-photos"
     * @param list<string> $attributes        the attributes that the service can supply, named as in the result
     *                                        models of the merges
     * @param string       $recheckEmptyAfter an ISO 8601 duration: the time to wait before the next check when the
     *                                        merged entity has no value from the service
     * @param string       $refreshAfter      an ISO 8601 duration: the time to wait before the next check when the
     *                                        merged entity has a value from the service
     * @param string       $requestTimeout    an ISO 8601 duration: the time that a requested check can wait in the
     *                                        queue before the run sends it again; it must be longer than the time
     *                                        that the queue of the service takes to empty
     */
    public function __construct(
        public string $id,
        public array $attributes,
        public string $recheckEmptyAfter,
        public string $refreshAfter,
        public string $requestTimeout = 'P1D',
    ) {
        if (1 !== preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $id)) {
            throw new \InvalidArgumentException(\sprintf('Lookup id "%s" must be in lower case with hyphens.', $id));
        }

        if ([] === $attributes) {
            throw new \InvalidArgumentException(\sprintf('Lookup %s declares no attributes.', $id));
        }

        foreach (['recheckEmptyAfter' => $recheckEmptyAfter, 'refreshAfter' => $refreshAfter, 'requestTimeout' => $requestTimeout] as $name => $duration) {
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
     * A check that the run requested less than requestTimeout ago is not due,
     * because its message waits in the queue. Otherwise, an entity that the
     * lookup never checked is due. An entity with a value from the lookup is
     * due when refreshAfter has passed since the last check. An entity without
     * a value is due when recheckEmptyAfter has passed.
     *
     * @param string|null $checkedAt   the observedAt of the check property, or null if there is none
     * @param bool        $hasValue    true if the entity has one or more values from the lookup
     * @param string|null $requestedAt the requestedAt of the check property, or null if there is none
     */
    public function isDue(?string $checkedAt, bool $hasValue, \DateTimeImmutable $now, ?string $requestedAt = null): bool
    {
        if (null !== $requestedAt && !self::hasPassed($requestedAt, $this->requestTimeout, $now)) {
            return false;
        }

        if (null === $checkedAt) {
            return true;
        }

        return self::hasPassed($checkedAt, $hasValue ? $this->refreshAfter : $this->recheckEmptyAfter, $now);
    }

    /**
     * Tests if a period has passed since a time. A time that is not valid has
     * always passed, so that a defect in the data does not stop the checks.
     */
    private static function hasPassed(string $since, string $period, \DateTimeImmutable $now): bool
    {
        try {
            return new \DateTimeImmutable($since)->add(new \DateInterval($period)) <= $now;
        } catch (\Exception) {
            return true;
        }
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
