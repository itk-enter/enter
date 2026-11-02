<?php

declare(strict_types=1);

namespace App\Command;

use App\Conflation\Lookup\LookupProbe;
use App\Conflation\Lookup\ProbeResult;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:lookup:check',
    description: 'Asks every remote service about one point, and tells if the service is available',
)]
class LookupCheckCommand
{
    public function __construct(
        private readonly LookupProbe $probe,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'The longitude of the point, in WGS 84. The default is the city hall of Aarhus')]
        float $longitude = LookupProbe::DEFAULT_POINT[0],
        #[Option(description: 'The latitude of the point, in WGS 84. The default is the city hall of Aarhus')]
        float $latitude = LookupProbe::DEFAULT_POINT[1],
    ): int {
        $results = $this->probe->probeAll($longitude, $latitude);
        if ([] === $results) {
            $io->warning('No remote service is declared.');

            return Command::SUCCESS;
        }

        $io->table(
            ['Service', 'Transport', 'Result', 'Time', 'Answer or error'],
            array_map(static fn (ProbeResult $result): array => [
                $result->id,
                $result->transport,
                $result->isAvailable() ? 'available' : 'failed',
                \sprintf('%d ms', $result->milliseconds),
                $result->error ?? self::answer($result->answer),
            ], $results),
        );

        $failed = array_filter($results, static fn (ProbeResult $result): bool => !$result->isAvailable());
        if ([] !== $failed) {
            $io->error(\sprintf('%d of %d services failed.', \count($failed), \count($results)));

            return Command::FAILURE;
        }

        $io->success(\sprintf('All %d services are available.', \count($results)));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed>|null $answer
     */
    private static function answer(?array $answer): string
    {
        if (null === $answer) {
            return 'No answer for the point';
        }

        return implode("\n", array_map(
            static fn (string $name, mixed $value): string => $name.': '.(\is_scalar($value) ? (string) $value : json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
            array_keys($answer),
            $answer,
        ));
    }
}
