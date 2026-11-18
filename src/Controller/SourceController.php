<?php

declare(strict_types=1);

namespace App\Controller;

use App\DataSetCatalog;
use App\Import\History\ImportHistory;
use App\SourceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shows the sources as a page and as JSON, and the import history of each source.
 */
final class SourceController extends AbstractController
{
    private const string FORMAT_HTML = 'html';
    private const string FORMAT_JSON = 'json';

    private const int EVENTS = 30;
    private const int ENTITY_CHANGES = 50;

    #[Route(
        path: '/sources.{_format}',
        name: 'app_sources',
        requirements: ['_format' => self::FORMAT_HTML.'|'.self::FORMAT_JSON],
        defaults: ['_format' => self::FORMAT_HTML],
        methods: [Request::METHOD_GET],
    )]
    public function index(DataSetCatalog $catalog, string $_format): Response
    {
        if (self::FORMAT_JSON === $_format) {
            return new JsonResponse($catalog->toArray());
        }

        return $this->render('source/index.html.twig', $catalog->all());
    }

    #[Route(
        path: '/sources/{id}',
        name: 'app_source_show',
        requirements: ['id' => '[^/]+'],
        methods: [Request::METHOD_GET],
    )]
    public function show(SourceManager $sources, ImportHistory $history, string $id): Response
    {
        $source = $sources->getSources()[$id] ?? throw $this->createNotFoundException(\sprintf('The source "%s" does not exist.', $id));
        $histories = $history->bySource($id);

        return $this->render('source/show.html.twig', [
            'source' => $source,
            'histories' => $histories,
            'history' => $histories[$id] ?? null,
            'changes' => null === $histories ? null : $history->entityChanges($id, self::ENTITY_CHANGES),
            'events' => self::EVENTS,
        ]);
    }
}
