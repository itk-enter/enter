<?php

declare(strict_types=1);

namespace App\Controller;

use App\Source\SourceInterface;
use App\SourceImporter\AbstractSourceImporter;
use App\SourceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Shows the sources as a page and as JSON.
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
    public function index(SourceManager $manager, string $_format): Response
    {
        $sources = array_values($manager->getSources());

        $entitiesUrls = [];
        foreach ($sources as $source) {
            $entitiesUrls[$source->definition->id] = $this->entitiesUrl($source);
        }

        if (self::FORMAT_JSON === $_format) {
            return new JsonResponse(array_map(
                static fn (SourceInterface $source): array => [
                    ...$source->toArray(),
                    'entities_url' => $entitiesUrls[$source->definition->id],
                ],
                $sources,
            ));
        }

        return $this->render('source/index.html.twig', [
            'sources' => $sources,
            'entitiesUrls' => $entitiesUrls,
        ]);
    }

    /**
     * Gets the broker URL for the entities of a source.
     */
    private function entitiesUrl(SourceInterface $source): string
    {
        return $this->generateUrl('ngsi_ld_v1_request', [
            'path' => 'entities',
            'q' => \sprintf('%s=="%s"', AbstractSourceImporter::SOURCE_ID_ATTRIBUTE, $source->definition->id),
        ], UrlGeneratorInterface::ABSOLUTE_URL);
    }
}
