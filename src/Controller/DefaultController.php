<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;

final class DefaultController extends AbstractController
{
    #[Route('/{path}', name: 'app_default', requirements: ['path' => Requirement::CATCH_ALL], methods: [Request::METHOD_GET], priority: -9999)]
    public function index(?string $path = null): Response
    {
        return $this->render(null === $path ? 'default/index.html.twig' : sprintf('default/%s.html.twig', $path));
    }
}
