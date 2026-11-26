<?php

declare(strict_types=1);

namespace App\Import;

use App\Source\SourceInterface;

/**
 * Puts the sources that read the same feed right after each other.
 *
 * Some feeds hold records of several models, so several sources read the
 * same feed. The source reader keeps a feed in its cache for a short time.
 * If the sources of one feed import right after each other, the feed is read
 * one time only, and all the sources get the same version of it.
 *
 * Two sources read the same feed if they have the same access URL and query.
 * The groups keep the order of their first source, and the sources in a group
 * keep their order.
 */
final class FeedOrder
{
    /**
     * @param iterable<SourceInterface> $sources
     *
     * @return list<SourceInterface>
     */
    public static function sort(iterable $sources): array
    {
        $groups = [];
        foreach ($sources as $source) {
            $feed = json_encode([$source->definition->accessUrlBase(), $source->definition->accessUrlQuery()], \JSON_THROW_ON_ERROR);
            $groups[$feed][] = $source;
        }

        return array_merge(...array_values($groups));
    }
}
