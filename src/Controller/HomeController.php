<?php

namespace App\Controller;

use JsonException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

final class HomeController extends AbstractController
{
    public function __invoke(#[Autowire('%kernel.project_dir%')] string $projectDir): Response
    {
        try {
            $places = json_decode((string) file_get_contents($projectDir.'/docs/examples/places.json'), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new \RuntimeException('Invalid contractual UI fixture.', previous: $exception);
        }

        return $this->render('home/index.html.twig', [
            'places' => $places['items'],
        ]);
    }
}
