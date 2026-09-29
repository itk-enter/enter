<?php

declare(strict_types=1);

namespace App\Controller;

use App\SourceManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The sources published to the broker, as a page and as JSON.
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

        if (self::FORMAT_JSON === $_format) {
            return new JsonResponse($sources);
        }

        return $this->render('source/index.html.twig', ['sources' => $sources]);
    }
}
