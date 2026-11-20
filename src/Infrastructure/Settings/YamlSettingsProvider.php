<?php

declare(strict_types=1);

namespace App\Infrastructure\Settings;

use App\Application\Settings\InvalidSettingsException;
use App\Application\Settings\Settings;
use App\Application\Settings\SettingsProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Reads the settings file on first use and again whenever it changes, so a running worker picks up edits.
 * Without a file, the defaults apply. If an edit makes the file invalid, the last valid settings stay in use until
 * the next edit and the error is logged; only a file that was never valid throws.
 */
final class YamlSettingsProvider implements SettingsProviderInterface
{
    private ?Settings $settings = null;
    private int|false|null $loadedVersion = null;

    public function __construct(
        #[Autowire(env: 'resolve:CAMERA_DIARY_CONFIG')]
        private readonly string $path,
        private readonly LoggerInterface $logger,
        private readonly SettingsBuilder $builder = new SettingsBuilder(),
    ) {
    }

    public function settings(): Settings
    {
        $version = $this->version();
        if ($this->settings instanceof Settings && $version === $this->loadedVersion) {
            return $this->settings;
        }

        try {
            $settings = $this->builder->build(false === $version ? [] : $this->read(), \dirname($this->path));
        } catch (InvalidSettingsException $exception) {
            if (!$this->settings instanceof Settings) {
                throw $this->invalid($exception->getMessage(), $exception);
            }

            $this->logger->error('Keeping the last valid settings: {message}', ['message' => $exception->getMessage()]);
            $this->loadedVersion = $version;

            return $this->settings;
        }

        $this->loadedVersion = $version;

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

    private function read(): mixed
    {
        try {
            return Yaml::parseFile($this->path);
        } catch (ParseException $exception) {
            throw new InvalidSettingsException($exception->getMessage(), 0, $exception);
        }
    }

    private function invalid(string $message, ?Throwable $previous = null): InvalidSettingsException
    {
        return new InvalidSettingsException(\sprintf('Invalid settings in "%s": %s', $this->path, $message), 0, $previous);
    }
}
