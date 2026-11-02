<?php

declare(strict_types=1);

namespace App\Conflation\Lookup;

/**
 * The value of the check property of a lookup: the result of the last check.
 */
enum CheckResult: string
{
    /**
     * The service gave one or more values.
     */
    case Found = 'found';

    /**
     * The service had no answer for the location. The merged entity keeps the
     * values that it had.
     */
    case None = 'none';
}
