<?php

declare(strict_types=1);

namespace App\Controller;

use App\DataSetCatalog;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function index(DataSetCatalog $catalog, string $_format): Response
    {
        if (self::FORMAT_JSON === $_format) {
            return new JsonResponse($catalog->toArray());
        }

        return $this->render('source/index.html.twig', $catalog->all());
    }
}
