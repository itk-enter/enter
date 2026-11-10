<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Sets the default time zone of PHP to UTC.
     *
     * The web server and the worker can have different default time zones,
     * and a DATETIME column has no time zone. With UTC in every process, each
     * date that the app makes and stores is in UTC. Change a date to local time
     * only to show it.
     */
    public function __construct(string $environment, bool $debug)
    {
        parent::__construct($environment, $debug);
        date_default_timezone_set('UTC');
    }

    /**
     * @return list<string> the allowed values of APP_ENV
     */
    private function getAllowedEnvs(): array
    {
        return ['prod', 'dev', 'test'];
    }
}
