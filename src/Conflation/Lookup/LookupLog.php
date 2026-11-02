<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

use Monolog\Logger;
use Psr\Log\LoggerInterface;

/**
 * Gives each lookup a log channel of its own, for example
 * "lookup.aerial-photos".
 *
 * The channels share the handlers of the "lookup" channel. In prod, a
 * handler writes them to stdout. Thus, to find the failures of one service,
 * filter the log on its channel.
 */
final class LookupLog
{
    /**
     * The Monolog channel that the logs of all the lookups go through.
     */
    public const string CHANNEL = 'lookup';

    /**
     * The logger of one lookup.
     *
     * @param LoggerInterface $logger the logger of the "lookup" channel
     * @param string          $id     the id of the lookup
     */
    public static function for(LoggerInterface $logger, string $id): LoggerInterface
    {
        return $logger instanceof Logger ? $logger->withName(self::CHANNEL.'.'.$id) : $logger;
    }
}
