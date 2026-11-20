<?php

declare(strict_types=1);

namespace App\Import;

use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * Imports one source in a PHP process of its own, with app:source:import.
 *
 * If PHP stops during the import, for example when it runs out of memory, only
 * the process of that source stops. The process has no time limit, because the
 * feed client and the broker client have their own timeouts.
 */
final readonly class SourceProcessImporter
{
    /**
     * @param list<string> $arguments the arguments to PHP that import one source, without the id of the source. The
     *                                importer reads the PHP binary when it runs, because the container can be
     *                                compiled by another PHP binary, for example php-fpm.
     */
    public function __construct(
        #[Autowire(['%kernel.project_dir%/bin/console', 'app:source:import', '--env=%kernel.environment%'])]
        private array $arguments,
    ) {
    }

    /**
     * @param callable(string): void $onOutput gets the output of the process while it runs
     *
     * @return bool true if the import succeeded
     */
    public function import(SourceInterface $source, callable $onOutput): bool
    {
        $process = new Process([\PHP_BINARY, ...$this->arguments, $source->definition->id]);
        $process->setTimeout(null);
        $process->run(static function (string $type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });

        return $process->isSuccessful();
    }
}
