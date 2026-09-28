<?php

namespace App\Controller;

use App\Source\SourceInterface;
use App\SourceManager;
use App\Test\Map\SourceFeatures;
use App\Test\Source\TestDefinition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

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
     * The developer map. The page fetches the data sets and draws each as
     * it is switched on.
     */
    #[Route('', name: 'default', methods: [Request::METHOD_GET])]
    public function index(): Response
    {
        return $this->render('test/index.html.twig');
    }

    #[Route(
        path: '/data/{path}.{_format}',
        requirements: [
            'path' => Requirement::CATCH_ALL,
            '_format' => 'json|geojson',
        ],
        defaults: ['_format' => self::FORMAT_JSON],
        methods: [Request::METHOD_GET],
        priority: -98,
    )]
    public function data(Request $request, string $path, string $_format): Response
    {
        // Remove "/test/"
        $path = substr($request->getRequestUri(), 6);
        $path = realpath(__DIR__.'/../../tests/resources/'.$path);
        if (!file_exists($path)) {
            throw new NotFoundHttpException($path);
        }

        $contentType = match ($_format) {
            self::FORMAT_GEOJSON => self::APPLICATION_GEOJSON,
            default => self::APPLICATION_JSON,
        };

        return new BinaryFileResponse($path, headers: [
            'content-type' => $contentType,
        ]);
    }

    /**
     * What a given test source currently holds in the broker, as plain GeoJSON.
     */
    #[Route(
        path: '/map/{sourceId}.{_format}',
        name: 'map',
        requirements: ['sourceId' => '[^/.]+', '_format' => self::FORMAT_GEOJSON],
        defaults: ['_format' => self::FORMAT_GEOJSON],
        methods: [Request::METHOD_GET],
    )]
    public function map(
        string $sourceId,
        SourceManager $manager,
        SourceFeatures $features,
    ): JsonResponse {
        $source = $this->testSources($manager)[$sourceId]
            ?? throw new NotFoundHttpException(sprintf('No test source "%s".', $sourceId));

        return new JsonResponse($features->forSource($source), headers: [
            'content-type' => self::APPLICATION_GEOJSON,
        ]);
    }

    /**
     * The data sets the map may draw, and where to fetch each.
     */
    #[Route(
        path: '/datasets.{_format}',
        name: 'datasets',
        requirements: ['_format' => self::FORMAT_JSON],
        defaults: ['_format' => self::FORMAT_JSON],
        methods: [Request::METHOD_GET],
    )]
    public function datasets(SourceManager $manager): JsonResponse
    {
        $datasets = [];
        foreach ($this->testSources($manager) as $id => $source) {
            $datasets[] = [
                'id' => $id,
                'title' => $source->definition->title,
                'model' => $source->definition->model,
                'url' => $this->generateUrl('test_map', ['sourceId' => $id]),
            ];
        }

        return new JsonResponse($datasets);
    }

    /**
     * @return array<string, SourceInterface>
     */
    private function testSources(SourceManager $manager): array
    {
        return array_filter(
            $manager->getSources(),
            static fn (SourceInterface $source): bool => $source->definition instanceof TestDefinition
        );
    }
}
