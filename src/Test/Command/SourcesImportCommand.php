<?php

namespace App\Test\Command;

use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;

#[AsCommand(
    name: 'test:source:import-all',
    description: 'Import all test sources',
)]
#[When('dev')]
class SourcesImportCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        OutputInterface $output,
        Application $application,
    ): int {
        foreach ($manager->getSources() as $source) {
            $definition = $source->definition;
            if (!$definition instanceof TestDefinition) {
                continue;
            }

            try {
                $io->section($source);
                $input = new ArrayInput([
                    'command' => 'app:source:import',
                    'source' => $source->definition->id,
                ]);
                $input->setInteractive(false);

                $application->doRun($input, $output);
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
