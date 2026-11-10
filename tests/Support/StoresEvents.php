<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Search\EventIndexerInterface;
use App\Domain\Model\CameraEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Saves and indexes events, and writes a small JPEG for each of their frames.
 */
trait StoresEvents
{
    private function storeEvents(ContainerInterface $container, CameraEvent ...$events): void
    {
        $entityManager = $container->get(EntityManagerInterface::class);
        foreach ($events as $event) {
            $entityManager->persist($event->getCamera());
            $entityManager->persist($event);
        }
        $entityManager->flush();

        $indexer = $container->get(EventIndexerInterface::class);
        $frameStorage = $container->get(FrameStorageInterface::class);

        foreach ($events as $event) {
            $indexer->index($event);
            foreach ($event->getFramePaths() as $framePath) {
                $image = imagecreatetruecolor(8, 4);
                \assert(false !== $image);
                $path = $frameStorage->absolutePath($framePath);
                new Filesystem()->mkdir(\dirname($path));
                imagejpeg($image, $path);
            }
        }
    }
}
