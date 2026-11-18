<?php

declare(strict_types=1);

namespace App\Infrastructure\Settings;

use App\Application\Settings\AiSettings;
use App\Application\Settings\CallPool;
use App\Application\Settings\DescriptionSettings;
use App\Application\Settings\Individual;
use App\Application\Settings\InvalidSettingsException;
use App\Application\Settings\MaintenanceSettings;
use App\Application\Settings\QuestionSettings;
use App\Application\Settings\Settings;
use App\Application\Settings\Term;
use App\Application\Settings\Vocabulary;
use App\Domain\Model\TermName;
use Symfony\Component\Config\Definition\Exception\Exception as ConfigException;
use Symfony\Component\Config\Definition\Processor;
use Symfony\Component\Filesystem\Path;

/**
 * Validates raw settings against SettingsConfiguration and fills in the defaults.
 *
 * @phpstan-type VocabularyConfig array<string, array{enabled: bool, label: ?string, description: ?string}>
 * @phpstan-type Config array{
 *     ai: array{daily_call_limit: int, call_pools: array<string, array{detection_types: list<string>, daily_call_limit: int}>, home_context: string, unusual_rule: string, reference_photos: int, correction_examples: int, scene_baseline: bool},
 *     detection_types: VocabularyConfig,
 *     subjects: VocabularyConfig,
 *     false_alarm_causes: VocabularyConfig,
 *     individuals: array<string, array{label: ?string, subject: string, description: ?string, photos: list<string>, detection_types: list<string>}>,
 *     description: array{frame_offsets: non-empty-list<int>, retry_delay: int, max_attempts: int, footage_grace: int, batch_size: int},
 *     questions: array{search_results: int, answer_events: int, frames_per_event: int, context_events: int},
 *     maintenance: array{nightly_at: string, false_alarm_frame_days: int, backups_kept: int},
 * }
 */
final readonly class SettingsBuilder
{
    /**
     * @param ?string $directory the settings file's directory, which relative photo paths start from
     *
     * @throws InvalidSettingsException
     */
    public function build(mixed $raw, ?string $directory = null): Settings
    {
        return $this->settings($this->process($raw), $directory);
    }

    /**
     * @return Config
     */
    private function process(mixed $raw): array
    {
        try {
            /** @var Config */
            return new Processor()->processConfiguration(new SettingsConfiguration(), [$raw ?? []]);
        } catch (ConfigException $exception) {
            throw new InvalidSettingsException($exception->getMessage(), 0, $exception);
        }
    }

    /**
     * @param Config $config
     */
    private function settings(array $config, ?string $directory): Settings
    {
        $detectionTypes = $this->vocabulary('detection_types', SettingsConfiguration::DEFAULT_DETECTION_TYPES, $config['detection_types']);
        $falseAlarmCauses = $this->vocabulary('false_alarm_causes', SettingsConfiguration::DEFAULT_FALSE_ALARM_CAUSES, $config['false_alarm_causes']);
        if ($falseAlarmCauses->has('none')) {
            throw new InvalidSettingsException('"none" is reserved for real events and cannot be a false-alarm cause.');
        }

        $subjects = $this->vocabulary('subjects', SettingsConfiguration::DEFAULT_SUBJECTS, $config['subjects']);
        $ai = $config['ai'];
        $description = $config['description'];
        $questions = $config['questions'];
        $maintenance = $config['maintenance'];
        $nightlyAt = $maintenance['nightly_at'];

        return new Settings(
            $detectionTypes,
            $subjects,
            $falseAlarmCauses,
            $this->individuals($config['individuals'], $subjects, $detectionTypes, $directory),
            new AiSettings($ai['daily_call_limit'], $this->callPools($ai['call_pools'], $detectionTypes), trim($ai['home_context']), trim($ai['unusual_rule']), $ai['reference_photos'], $ai['correction_examples'], $ai['scene_baseline']),
            new DescriptionSettings($description['frame_offsets'], $description['retry_delay'], $description['max_attempts'], $description['footage_grace'], $description['batch_size']),
            new QuestionSettings($questions['search_results'], $questions['answer_events'], $questions['frames_per_event'], $questions['context_events']),
            new MaintenanceSettings((int) substr($nightlyAt, 0, 2), (int) substr($nightlyAt, 3, 2), $maintenance['false_alarm_frame_days'], $maintenance['backups_kept']),
        );
    }

    /**
     * @param array<string, array{label: string, description?: string}> $defaults
     * @param VocabularyConfig                                          $configured
     */
    private function vocabulary(string $key, array $defaults, array $configured): Vocabulary
    {
        $entries = $defaults;
        foreach ($configured as $name => $entry) {
            if (!TermName::isValid($name)) {
                throw new InvalidSettingsException(\sprintf('"%s" in %s: use lower-case letters, digits and "_", starting with a letter, at most 32 characters.', $name, $key));
            }

            if (!$entry['enabled']) {
                unset($entries[$name]);

                continue;
            }

            $entries[$name] = array_filter([
                'label' => $entry['label'] ?? $defaults[$name]['label'] ?? Vocabulary::humanize($name),
                'description' => $entry['description'] ?? $defaults[$name]['description'] ?? null,
            ], static fn (?string $value): bool => null !== $value);
        }

        if ([] === $entries) {
            throw new InvalidSettingsException(\sprintf('%s needs at least one entry.', $key));
        }

        $terms = [];
        foreach ($entries as $name => $entry) {
            $terms[] = new Term($name, $entry['label'], $entry['description'] ?? null);
        }

        return new Vocabulary($terms);
    }

    /**
     * @param array<string, array{label: ?string, subject: string, description: ?string, photos: list<string>, detection_types: list<string>}> $configured
     *
     * @return list<Individual>
     */
    private function individuals(array $configured, Vocabulary $subjects, Vocabulary $detectionTypes, ?string $directory): array
    {
        $individuals = [];
        foreach ($configured as $name => $individual) {
            if (!TermName::isValid($name) || Individual::UNKNOWN === $name) {
                throw new InvalidSettingsException(\sprintf('"%s" in individuals: use lower-case letters, digits and "_", starting with a letter, at most 32 characters; "%s" is reserved.', $name, Individual::UNKNOWN));
            }

            if (!$subjects->has($individual['subject'])) {
                throw new InvalidSettingsException(\sprintf('Individual "%s": unknown subject "%s". Known: %s.', $name, $individual['subject'], implode(', ', $subjects->names())));
            }

            foreach ($individual['detection_types'] as $type) {
                if (!$detectionTypes->has($type)) {
                    throw new InvalidSettingsException(\sprintf('Individual "%s": unknown detection type "%s". Known: %s.', $name, $type, implode(', ', $detectionTypes->names())));
                }
            }

            $individuals[] = new Individual(
                $name,
                $individual['label'] ?? Vocabulary::humanize($name),
                $individual['subject'],
                $individual['description'],
                array_map(fn (string $photo): string => $this->photo($name, $photo, $directory), $individual['photos']),
                array_values(array_unique($individual['detection_types'])),
            );
        }

        return $individuals;
    }

    private function photo(string $individual, string $photo, ?string $directory): string
    {
        $path = null === $directory ? $photo : Path::makeAbsolute($photo, $directory);
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidSettingsException(\sprintf('Individual "%s": cannot read the photo "%s".', $individual, $path));
        }

        return $path;
    }

    /**
     * @param array<string, array{detection_types: list<string>, daily_call_limit: int}> $configured
     *
     * @return list<CallPool>
     */
    private function callPools(array $configured, Vocabulary $detectionTypes): array
    {
        $pools = [];
        foreach ($configured as $name => $pool) {
            foreach ($pool['detection_types'] as $type) {
                if (!$detectionTypes->has($type)) {
                    throw new InvalidSettingsException(\sprintf('Call pool "%s": unknown detection type "%s". Known: %s.', $name, $type, implode(', ', $detectionTypes->names())));
                }
            }

            $pools[] = new CallPool($name, array_values(array_unique($pool['detection_types'])), $pool['daily_call_limit']);
        }

        return $pools;
    }
}
