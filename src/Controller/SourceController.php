<?php

declare(strict_types=1);

namespace App\Controller;

use App\Conflation\ConflationManager;
use App\Conflation\Io\MergedDataSet;
use App\Conflation\Io\MergedDataSetFactory;
use App\Source\SourceInterface;
use App\SourceImporter\AbstracSourceImporter;
use App\SourceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The data sets published to the broker, as a page and as JSON: every source,
 * then every merged data set.
 */
final class SourceController extends AbstractController
{
    private const string FORMAT_HTML = 'html';
    private const string FORMAT_JSON = 'json';

    #[Route(
        path: '/sources.{_format}',
        name: 'app_sources',
        requirements: ['_format' => self::FORMAT_HTML.'|'.self::FORMAT_JSON],
        defaults: ['_format' => self::FORMAT_HTML],
        methods: [Request::METHOD_GET],
    )]
    public function index(SourceManager $manager, ConflationManager $conflations, MergedDataSetFactory $mergedDataSets, string $_format): Response
    {
        $sources = array_values($manager->getSources());
        $merged = array_map($mergedDataSets->create(...), array_values($conflations->getConflations()));

        $entitiesUrls = [];
        foreach ($sources as $source) {
            $entitiesUrls[$source->definition->id] = $this->entitiesUrl($source->definition->id);
        }
        foreach ($merged as $dataSet) {
            $entitiesUrls[$dataSet->id] = $this->entitiesUrl($dataSet->id);
        }

        if (self::FORMAT_JSON === $_format) {
            return new JsonResponse([
                ...array_map(
                    static fn (SourceInterface $source): array => [
                        ...$source->toArray(),
                        'entities_url' => $entitiesUrls[$source->definition->id],
                    ],
                    $sources,
                ),
                ...array_map(
                    static fn (MergedDataSet $dataSet): array => [
                        ...$dataSet->toArray(),
                        'entities_url' => $entitiesUrls[$dataSet->id],
                    ],
                    $merged,
                ),
            ]);
        }

        return $this->render('source/index.html.twig', [
            'sources' => $sources,
            'merged' => $merged,
            'entitiesUrls' => $entitiesUrls,
        ]);
    }

    /**
     * Where a data set's entities are read from the broker: everything
     * stamped with its id, whatever the model.
     */
    private function entitiesUrl(string $sourceId): string
    {
        return $this->generateUrl('ngsi_ld_v1_request', [
            'path' => 'entities',
            'q' => \sprintf('%s=="%s"', AbstracSourceImporter::SOURCE_ID_ATTRIBUTE, $sourceId),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
