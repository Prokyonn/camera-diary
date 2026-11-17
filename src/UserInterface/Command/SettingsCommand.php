<?php

declare(strict_types=1);

namespace App\UserInterface\Command;

use App\Application\Settings\InvalidSettingsException;
use App\Application\Settings\SettingsProviderInterface;
use App\Application\Settings\Term;
use App\Application\Settings\Vocabulary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand('app:settings', 'Check the settings file and show the vocabularies and AI limits in effect')]
final readonly class SettingsCommand
{
    public function __construct(
        private SettingsProviderInterface $settings,
        #[Autowire(env: 'resolve:CAMERA_DIARY_CONFIG')]
        private string $path,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        try {
            $settings = $this->settings->settings();
        } catch (InvalidSettingsException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->text(is_file($this->path) ? \sprintf('Settings file: %s', $this->path) : \sprintf('No settings file at "%s"; the defaults apply.', $this->path));

        $rows = [
            ['Detection types', $this->terms($settings->detectionTypes)],
            ['Subjects', $this->terms($settings->subjects)],
            ['False-alarm causes', $this->terms($settings->falseAlarmCauses)],
        ];
        foreach ($settings->individuals as $individual) {
            $rows[] = [\sprintf('Individual "%s"', $individual->name), \sprintf('%s, a %s, %d reference photos', $individual->label, $individual->subject, \count($individual->photos))];
        }
        $rows[] = ['AI calls per day', (string) $settings->ai->dailyCallLimit];
        foreach ($settings->ai->callPools as $pool) {
            $rows[] = [\sprintf('Pool "%s"', $pool->name), \sprintf('%d per day for %s', $pool->dailyCallLimit, implode(', ', $pool->detectionTypes))];
        }

        $io->table([], $rows);
        $io->success('The settings are valid.');

        return Command::SUCCESS;
    }

    private function terms(Vocabulary $vocabulary): string
    {
        return implode(', ', array_map(static fn (Term $term): string => $term->name, $vocabulary->terms));
    }
}
