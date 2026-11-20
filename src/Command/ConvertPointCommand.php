<?php

namespace App\Command;

use App\GeometryHelper;
use App\GeometryHelper\Crs;
use proj4php\Point;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:convert:point',
    description: 'Convert a point from one projection to another',
)]
class ConvertPointCommand
{
    public function __invoke(
        SymfonyStyle $io,
        GeometryHelper $helper,
        #[Argument]
        float $longitude,
        #[Argument]
        float $latitude,
        #[Option]
        string $from = 'EPSG:4326',
        #[Option]
        string $to = 'EPSG:4326',
    ): int {
        $from = Crs::from($from);
        $to = Crs::from($to);
        $point = new Point($longitude, $latitude, $helper->createProjection($from->value));
        $transformedPoint = $helper->transformPoint($point, to: $to);

        $io->writeln([
            $point->toShortString(),
            $from->name,
            $transformedPoint->toShortString(),
            $to->name,
        ]);

        return Command::SUCCESS;
    }
}
