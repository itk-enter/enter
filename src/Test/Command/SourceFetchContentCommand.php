<?php

namespace App\Test\Command;

use App\SourceManager;
use App\Test\Source\TestDefinition;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'test:source:fetch-content',
    description: 'Fetch content for all test sources',
)]
#[When('dev')]
class SourceFetchContentCommand
{
    public function __invoke(
        SymfonyStyle $io,
        SourceManager $manager,
        HttpClientInterface $httpClient,
        Filesystem $filesystem,
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
                $url = $definition->dataUrlBase;
                $query = $definition->dataUrlQuery;
                // Extract content filename from source data URL (the path must start with `/test`).
                $filename = preg_replace('@^[a-z]+://[^/]+/test/@', '', $definition->accessUrl);
                if ($filename === $definition->accessUrl) {
                    throw new \RuntimeException(sprintf('Invalid test source URL: %s. Its path must start with "/test/".', $definition->accessUrl));
                }
                $filename = __DIR__.'/../../../tests/resources/'.$filename;

                if ($filesystem->exists($filename)) {
                    $filesystem->remove($filename);
                }

                $io->writeln(sprintf('Fetching "%s"', $url));
                $response = $httpClient->request(Request::METHOD_GET, $url, [
                    'query' => $query,
                ]);
                $content = $response->getContent();
                $filesystem->dumpFile($filename, $content);
                $io->success(sprintf('Content written to file %s', realpath($filename)));
            } catch (\Exception $e) {
                $io->error($e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
