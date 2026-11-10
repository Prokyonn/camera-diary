<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\Settings;

use App\Domain\Model\DetectionType;
use CameraEvents\HomeAssistant\Application\Settings\CameraMapping;
use CameraEvents\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraEvents\HomeAssistant\Application\Settings\InvalidSettingsException;
use CameraEvents\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraEvents\HomeAssistant\Application\Settings\SignalMapping;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads the YAML file at HOME_ASSISTANT_CONFIG; the add-on stays idle while it is missing.
 */
final readonly class YamlSettingsProvider implements SettingsProviderInterface
{
    public function __construct(
        #[Autowire(env: 'resolve:HOME_ASSISTANT_CONFIG')]
        private string $path,
    ) {
    }

    public function settings(): HomeAssistantSettings
    {
        if ('' === $this->path || !is_file($this->path)) {
            return new HomeAssistantSettings();
        }

        try {
            /** @var array{cameras: array<string, array{name: ?string, scene: ?string, sensor_prefix: string, detections: list<string>, recording_folder: ?string}>, signals: array<string, array{time_attribute: ?string, states: array<string, string>}>} $config */
            $config = new Processor()->processConfiguration(new SettingsConfiguration(), [Yaml::parseFile($this->path) ?? []]);
        } catch (ParseException|ConfigException $exception) {
            throw new InvalidSettingsException(\sprintf('Invalid Home Assistant settings in "%s": %s', $this->path, $exception->getMessage()), 0, $exception);
        }

        $cameras = [];
        foreach ($config['cameras'] as $slug => $camera) {
            $cameras[] = new CameraMapping(
                $slug,
                $camera['sensor_prefix'],
                array_map(DetectionType::from(...), $camera['detections']),
                $camera['name'],
                $camera['scene'],
                $camera['recording_folder'],
            );
        }

        $signals = [];
        foreach ($config['signals'] as $entityId => $signal) {
            $signals[] = new SignalMapping($entityId, $signal['states'], $signal['time_attribute']);
        }

        return new HomeAssistantSettings($cameras, $signals);
    }
}
