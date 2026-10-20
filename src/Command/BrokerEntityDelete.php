<?php

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\HttpClientInterface;

#[AsCommand(
    name: 'app:broker:entity:delete',
    description: 'Delete all entities with the given types',
)]
class BrokerEntityDelete
{
    /**
     * @param string[]|null $entityTypes
     */
    public function __invoke(
        SymfonyStyle $io,
        HttpClientInterface $brokerClient,
        #[Argument(name: 'type')]
        ?array $entityTypes = null,
        #[Option]
        bool $all = false,
    ): int {
        if ($all) {
            $data = $brokerClient->request(Request::METHOD_GET, '/ngsi-ld/v1/types')->toArray();
            $entityTypes = $data['typeList'] ?? [];
        } else {
            if (0 === count($entityTypes)) {
                throw new RuntimeException('Missing entity types');
            }
        }

        $limit = 1000;
        foreach ($entityTypes as $entityType) {
            while (true) {
                /**
                 * @var array<array{
                 *     type: string,
                 *     id: string
                 * }> $entities
                 */
                $response = $brokerClient->request(Request::METHOD_GET, '/ngsi-ld/v1/entities', [
                    'query' => [
                        'type' => $entityType,
                        'limit' => $limit,
                    ],
                ]);

                $entities = $response->toArray();
                $count = count($entities);
                if ($count > 0) {
                    $io->writeln(match ($count) {
                        1 => sprintf('Deleting one %s entity …', $entityType),
                        default => sprintf('Deleting %d %s entities …', $count, $entityType),
                    });
                    $io->progressStart($count);
                    foreach ($entities as $entity) {
                        $brokerClient->request(Request::METHOD_DELETE, '/ngsi-ld/v1/entities/'.urlencode($entity['id']))
                            ->getStatusCode();
                        $io->progressAdvance();
                    }
                    $io->progressFinish();
                }

                if ($count < $limit) {
                    break;
                }
            }
        }

        return Command::SUCCESS;
    }
}
