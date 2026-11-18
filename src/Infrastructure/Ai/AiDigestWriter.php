<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Application\Clock\HomeTimeZone;
use App\Application\Digest\DigestWriterInterface;
use App\Application\Settings\SettingsProviderInterface;
use App\Domain\Model\CameraEvent;
use App\Infrastructure\Ai\Client\Prompt;
use App\Infrastructure\Ai\Client\StructuredClientInterface;
use App\Infrastructure\Ai\Output\DigestOutput;
use DateTimeImmutable;

/**
 * Summarizes a day from the stored descriptions; text only, so it costs one small call.
 */
final readonly class AiDigestWriter implements DigestWriterInterface
{
    public function __construct(
        private StructuredClientInterface $client,
        private HomeTimeZone $homeTimeZone,
        private SettingsProviderInterface $settings,
        private HomeContext $homeContext,
    ) {
    }

    public function summarize(DateTimeImmutable $day, array $events): string
    {
        $lines = [\sprintf('Events of %s, oldest first:', $day->format('l, j F Y'))];
        foreach ($events as $event) {
            $lines[] = $this->line($event);
        }

        $output = $this->client->request(new Prompt(trim(<<<PROMPT
            You write the daily summary of a home camera diary for the people who live there.
            Lead with anything unusual, then who came by and when (known individuals by name; several events of the same animal or person are one visit when close together), then the false alarms in one short clause with their main cause.
            Use local times like "07:40". Be warm but factual, and do not invent anything.
            {$this->homeContext->notes()}
            PROMPT), [implode("\n", $lines)]), DigestOutput::class);

        return trim($output->text);
    }

    public function modelName(): string
    {
        return $this->client->modelName();
    }

    private function line(CameraEvent $event): string
    {
        $settings = $this->settings->settings();
        $subject = $event->getSubject();
        $cause = $event->getFalseAlarmCause();

        return \sprintf(
            '- %s %s: %s%s%s%s',
            $this->homeTimeZone->toLocal($event->getStartedAt())->format('H:i'),
            $event->getCamera()->getName(),
            $event->isFalseAlarm() ? 'false alarm'.(null === $cause ? '' : ' ('.$settings->falseAlarmCauses->label($cause).')') : (null === $subject ? 'real' : $settings->subjectLabel($subject, $event->getEffectiveIndividual())),
            $event->isUnusual() && !$event->isFalseAlarm() ? ', UNUSUAL' : '',
            null === $event->getLabel() ? '' : ', labelled "'.$event->getLabel().'"',
            $event->isFalseAlarm() ? '' : '. '.$event->getSummary(),
        );
    }
}
