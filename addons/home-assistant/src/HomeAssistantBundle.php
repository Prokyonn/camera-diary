<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

/**
 * Optional add-on: Home Assistant detections and signals, frames from continuous recordings.
 */
final class HomeAssistantBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'HomeAssistant' => [
                        'type' => 'attribute',
                        'is_bundle' => false,
                        'dir' => __DIR__.'/Domain/Model',
                        'prefix' => 'CameraDiary\HomeAssistant\Domain\Model',
                    ],
                ],
            ],
        ]);

        $builder->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => ['CameraDiary\HomeAssistant\Migrations' => __DIR__.'/../migrations'],
        ]);

        $builder->prependExtensionConfig('framework', [
            'http_client' => [
                'scoped_clients' => [
                    'home_assistant.client' => [
                        'base_uri' => '%env(HA_URL)%',
                        'headers' => ['Authorization' => 'Bearer %env(HA_TOKEN)%'],
                        'timeout' => 30,
                    ],
                ],
            ],
        ]);
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
    }
}
