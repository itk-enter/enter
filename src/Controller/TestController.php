<?php

declare(strict_types=1);

namespace App\Controller;

use App\Test\Fixture\FixtureFiles;
use App\Test\Map\MapDataSets;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Shows the developer map, gives its data and serves the fixture files of the test sources.
 */
#[When('dev')]
#[When('test')]
#[Route('/test', name: 'test_')]
final class TestController extends AbstractController
{
    private const string FORMAT_JSON = 'json';
    private const string FORMAT_GEOJSON = 'geojson';

    private const string APPLICATION_GEOJSON = 'application/geo+json';
    private const string APPLICATION_JSON = 'application/json';

    /**
     * Shows the developer map. The page fetches the data sets and draws a
     * data set when the user switches it on.
     */
    #[Route('', name: 'default', methods: [Request::METHOD_GET])]
    public function index(): Response
    {
        return $this->render('test/index.html.twig');
    }

    /**
     * Gives the test data sets that have entities in the broker.
     */
    #[Route('/data-sets.json', name: 'data_sets', methods: [Request::METHOD_GET])]
    public function dataSets(MapDataSets $dataSets): JsonResponse
    {
        return new JsonResponse($dataSets->all());
    }

    /**
     * Gives the entities of a test data set as a GeoJSON feature collection.
     */
    #[Route('/data-sets/{id}.geojson', name: 'features', requirements: ['id' => '[^/]+'], methods: [Request::METHOD_GET])]
    public function features(string $id, MapDataSets $dataSets): JsonResponse
    {
        $features = $dataSets->features($id) ?? throw new NotFoundHttpException();

        return new JsonResponse(
            ['type' => 'FeatureCollection', 'features' => $features],
            headers: ['Content-Type' => self::APPLICATION_GEOJSON],
        );
    }

    /**
     * Serves the fixture file of a test source. The query of the URL is a
     * part of the file name.
     *
     * The path is the shortest match. Thus a file name that ends in
     * ".geojson" gives the format, and the path does not take the extension.
     */
    #[Route(
        path: '/data/{path}.{_format}',
        methods: [Request::METHOD_GET],
        requirements: [
            'path' => '.+?',
            '_format' => 'json|geojson',
        ],
        defaults: ['_format' => self::FORMAT_JSON],
        priority: -98,
    )]
    public function data(Request $request, string $_format, FixtureFiles $fixtures): Response
    {
        $file = $fixtures->find(substr($request->getRequestUri(), \strlen('/test/')));
        if (null === $file) {
            throw new NotFoundHttpException();
        }

        $contentType = match ($_format) {
            self::FORMAT_GEOJSON => self::APPLICATION_GEOJSON,
            default => self::APPLICATION_JSON,
        };

        return new BinaryFileResponse($file, headers: [
            'content-type' => $contentType,
        ]);
    }
}
