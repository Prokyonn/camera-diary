<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\Settings;

use App\Application\Settings\Settings as CoreSettings;
use App\Application\Settings\SettingsProviderInterface as CoreSettingsProviderInterface;
use CameraDiary\HomeAssistant\Application\Settings\CameraMapping;
use CameraDiary\HomeAssistant\Application\Settings\HomeAssistantSettings;
use CameraDiary\HomeAssistant\Application\Settings\InvalidSettingsException;
use CameraDiary\HomeAssistant\Application\Settings\PushSettings;
use CameraDiary\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraDiary\HomeAssistant\Application\Settings\SignalMapping;
use CameraDiary\HomeAssistant\Domain\Model\GroupingRule;
use Psr\Log\LoggerInterface;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads the add-on's YAML file on first use and again whenever it or the core settings change, so edits apply without
 * a restart. Without a file, the add-on is idle. If an edit makes the file invalid, the last valid settings stay in
 * use until the next edit and the error is logged; only a file that was never valid throws.
 *
 * @phpstan-type Config array{
 *     cameras: array<string, array{name: ?string, scene: ?string, sensor_prefix: string, detections: list<string>, recording_folder: ?string}>,
 *     signals: array<string, array{time_attribute: ?string, states: array<string, string>}>,
 *     import: array{max_gap: int, max_duration: int, hint_window: int},
 *     push?: array{handover_entity: string, notify_service: string, url: ?string, details_title: string, loud_interruption_level: string, mute_actions: array<string, string>, max_age: int, frames: non-empty-list<'snapshot'|int|float>},
 * }
 */
final class YamlSettingsProvider implements SettingsProviderInterface
{
    private ?HomeAssistantSettings $settings = null;
    private int|false|null $loadedVersion = null;
    private ?CoreSettings $loadedCore = null;

    public function __construct(
        private readonly CoreSettingsProviderInterface $coreSettings,
        #[Autowire(env: 'resolve:HOME_ASSISTANT_CONFIG')]
        private readonly string $path,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function settings(): HomeAssistantSettings
    {
        $core = $this->coreSettings->settings();
        $version = $this->version();
        if ($this->settings instanceof HomeAssistantSettings && $version === $this->loadedVersion && $core === $this->loadedCore) {
            return $this->settings;
        }

        try {
            $settings = $this->build($core, false !== $version);
        } catch (InvalidSettingsException $exception) {
            if (!$this->settings instanceof HomeAssistantSettings) {
                throw $exception;
            }

            $this->logger->error('Keeping the last valid Home Assistant settings: {message}', ['message' => $exception->getMessage()]);
            $settings = $this->settings;
        }

        $this->loadedVersion = $version;
        $this->loadedCore = $core;

        return $this->settings = $settings;
    }

    /**
     * The file's modification time; false without a file.
     */
    private function version(): int|false
    {
        if ('' === $this->path) {
            return false;
        }

        clearstatcache(true, $this->path);

        return is_file($this->path) ? filemtime($this->path) : false;
    }

    private function build(CoreSettings $core, bool $exists): HomeAssistantSettings
    {
        try {
            /** @var Config $config */
            $config = new Processor()->processConfiguration(new SettingsConfiguration(), [$exists ? Yaml::parseFile($this->path) ?? [] : []]);
        } catch (ParseException|ConfigException $exception) {
            throw $this->invalid($exception->getMessage(), $exception);
        }

        return new HomeAssistantSettings(
            $this->cameras($config['cameras'], $core),
            $this->signals($config['signals']),
            new GroupingRule($config['import']['max_gap'], $config['import']['max_duration']),
            $config['import']['hint_window'],
            isset($config['push']) ? $this->push($config['push']) : null,
        );
    }

    /**
     * @param array<string, array{name: ?string, scene: ?string, sensor_prefix: string, detections: list<string>, recording_folder: ?string}> $configured
     *
     * @return list<CameraMapping>
     */
    private function cameras(array $configured, CoreSettings $core): array
    {
        $detectionTypes = $core->detectionTypes;
        $cameras = [];
        foreach ($configured as $slug => $camera) {
            foreach ($camera['detections'] as $type) {
                if (!$detectionTypes->has($type)) {
                    throw $this->invalid(\sprintf('Camera "%s": unknown detection type "%s". Known: %s.', $slug, $type, implode(', ', $detectionTypes->names())));
                }
            }

            $cameras[] = new CameraMapping(
                $slug,
                $camera['sensor_prefix'],
                array_values(array_unique($camera['detections'])),
                $camera['name'],
                $camera['scene'],
                $camera['recording_folder'],
            );
        }

        return $cameras;
    }

    /**
     * @param array<string, array{time_attribute: ?string, states: array<string, string>}> $configured
     *
     * @return list<SignalMapping>
     */
    private function signals(array $configured): array
    {
        $signals = [];
        foreach ($configured as $entityId => $signal) {
            $signals[] = new SignalMapping($entityId, $signal['states'], $signal['time_attribute']);
        }

        return $signals;
    }

    /**
     * @param array{handover_entity: string, notify_service: string, url: ?string, details_title: string, loud_interruption_level: string, mute_actions: array<string, string>, max_age: int, frames: non-empty-list<'snapshot'|int|float>} $push
     */
    private function push(array $push): PushSettings
    {
        return new PushSettings(
            $push['handover_entity'],
            $push['notify_service'],
            $push['url'],
            $push['details_title'],
            $push['loud_interruption_level'],
            $push['mute_actions'],
            $push['max_age'],
            array_map(static fn (string|int|float $frame): ?float => 'snapshot' === $frame ? null : (float) $frame, $push['frames']),
        );
    }

    private function invalid(string $message, ?Throwable $previous = null): InvalidSettingsException
    {
        return new InvalidSettingsException(\sprintf('Invalid Home Assistant settings in "%s": %s', $this->path, $message), 0, $previous);
    }
}
