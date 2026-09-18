<?php
namespace App\Tests\Integration;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
final class BootTest extends KernelTestCase
{
    public function testContainerBoots(): void
    {
        self::bootKernel();
        self::assertTrue(self::getContainer()->has('doctrine'));
        self::assertTrue(self::getContainer()->has('twig'));
    }
}
