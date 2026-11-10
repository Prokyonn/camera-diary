<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Infrastructure\Seal\SealEventIndexer;
use App\Tests\Factory\CameraFactory;
use CmsIg\Seal\EngineInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Gives each functional test an empty database, search index and data folder.
 */
trait ResetsStorage
{
    private function resetStorage(ContainerInterface $container): void
    {
        CameraFactory::reset();

        $projectDir = $container->getParameter('kernel.project_dir');

        $filesystem = new Filesystem();
        $filesystem->remove($projectDir.'/var/test/data');
        $filesystem->mkdir([$projectDir.'/var/test/data', $projectDir.'/var/test/search']);

        $entityManager = $container->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($entityManager);
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $connection = $entityManager->getConnection();
        $connection->executeStatement('PRAGMA foreign_keys = OFF');
        $schemaTool->dropDatabase();
        $connection->executeStatement('PRAGMA foreign_keys = ON');
        $schemaTool->createSchema($metadata);

        $engine = $container->get(EngineInterface::class);
        if ($engine->existIndex(SealEventIndexer::INDEX)) {
            $engine->dropIndex(SealEventIndexer::INDEX);
        }
        $engine->createIndex(SealEventIndexer::INDEX);
    }
}
