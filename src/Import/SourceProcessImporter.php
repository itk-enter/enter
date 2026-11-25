<?php

declare(strict_types=1);

namespace App\Import;

use App\Source\SourceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Imports one source in a PHP process of its own, with app:source:import.
 *
 * If PHP stops during the import, for example when it runs out of memory, only
 * the process of that source stops. If the import does not finish in time, the
 * importer stops the process and reports a failed import. Thus an import that
 * hangs does not block the sources after it.
 */
final readonly class SourceProcessImporter
{
    /**
     * @param list<string> $arguments the arguments to PHP that import one source, without the id of the source. The
     *                                importer reads the PHP binary when it runs, because the container can be
     *                                compiled by another PHP binary, for example php-fpm.
     * @param int          $timeout   the maximum time of one import, in seconds. The default is two hours, because
     *                                the retries of the feed client can take about one hour for one feed.
     */
    public function __construct(
        #[Autowire(['%kernel.project_dir%/bin/console', 'app:source:import', '--env=%kernel.environment%'])]
        private array $arguments,
        private int $timeout = 7200,
    ) {
    }

    /**
     * @param callable(string): void $onOutput gets the output of the process while it runs
     *
     * @return bool true if the import succeeded
     */
    public function import(SourceInterface $source, callable $onOutput): bool
    {
        $process = new Process([\PHP_BINARY, ...$this->arguments, $source->definition->id], timeout: $this->timeout);

        try {
            $process->run(static function (string $type, string $buffer) use ($onOutput): void {
                $onOutput($buffer);
            });
        } catch (ProcessTimedOutException) {
            $onOutput(\sprintf('The import did not finish in %d seconds. The importer stopped the process.', $this->timeout).\PHP_EOL);

            return false;
        }

        return $process->isSuccessful();
    }
}
