<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\When;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Yaml\Yaml;

#[When('dev')]
#[When('test')]
#[Route('/test', name: 'test_')]
final class TestController extends AbstractController
{
    private const string FORMAT_JSON = 'json';
    private const string FORMAT_GEOJSON = 'geojson';

    private const string APPLICATION_GEOJSON = 'application/geo+json';
    private const string APPLICATION_JSON = 'application/json';

    #[Route('/{path}', name: 'default', requirements: ['path' => Requirement::CATCH_ALL], methods: [Request::METHOD_GET], priority: -99)]
    public function index(?string $path = null): Response
    {
        return $this->render(null === $path ? 'test/index.html.twig' : sprintf('test/%s.html.twig', $path));
    }

    #[Route(
        path: '/data/{path}.{_format}',
        methods: [Request::METHOD_GET],
        requirements: [
            'path' => Requirement::CATCH_ALL,
            '_format' => 'json|geojson',
        ],
        defaults: ['_format' => self::FORMAT_JSON],
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

    #[Route('/config', name: 'config', methods: [Request::METHOD_GET])]
    public function config(
        #[MapQueryParameter('type')]
        string $type,
    ): JsonResponse {
        $configName = match ($type) {
            'https://smartdatamodels.org/dataModel.Parking/OnStreetParking' => 'Parking/OnStreetParking',
            default => throw new BadRequestHttpException('Invalid type'),
        };

        $data = Yaml::parseFile(__DIR__.'/../../tests/resources/config/'.$configName.'.yaml');

        return new JsonResponse($data);
    }
}
