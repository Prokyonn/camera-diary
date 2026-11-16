<?php

declare(strict_types=1);

namespace CameraDiary\HomeAssistant\Infrastructure\HomeAssistant;

use App\Application\Description\EventDescribed;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\CameraEvent;
use CameraDiary\HomeAssistant\Application\Push\EventHandovers;
use CameraDiary\HomeAssistant\Application\Settings\PushSettings;
use CameraDiary\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraDiary\HomeAssistant\Domain\Model\PushHandover;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Replaces the camera push Home Assistant sent with the AI verdict: same title, tag and clip, the description
 * appended, silent unless the AI flags the event as unusual.
 */
#[AsEventListener]
final readonly class VerdictPush
{
    /** An older push is no longer worth updating, e.g. after a day over the AI budget. */
    public const int MAX_AGE_SECONDS = 3600;

    public function __construct(
        private SettingsProviderInterface $settings,
        private EventHandovers $handovers,
        #[Target('home_assistant.client')]
        private HttpClientInterface $client,
        private UrlGeneratorInterface $urlGenerator,
        private UnitOfWorkInterface $unitOfWork,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(EventDescribed $described): void
    {
        $push = $this->settings->settings()->push;
        if (!$push instanceof PushSettings) {
            return;
        }

        $event = $described->event;
        $now = $this->clock->now();
        foreach ($this->handovers->forEvent($event) as $handover) {
            if ($handover->isUpdated() || $now->getTimestamp() - $handover->getPushedAt()->getTimestamp() > self::MAX_AGE_SECONDS) {
                continue;
            }

            try {
                $this->client->request('POST', '/api/services/notify/'.$push->notifyService, ['json' => $this->payload($push, $handover, $event)])->getContent();
                $handover->markUpdated($now);
            } catch (ExceptionInterface $exception) {
                $this->logger->warning('Could not update the push {tag}: {message}', ['tag' => $handover->getTag(), 'message' => $exception->getMessage()]);
            }
        }

        $this->unitOfWork->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushSettings $push, PushHandover $handover, CameraEvent $event): array
    {
        $actions = [[
            'action' => 'URI',
            'title' => $push->detailsTitle,
            'uri' => $this->urlGenerator->generate('deep_link', [
                'camera' => $event->getCamera()->getSlug(),
                't' => $handover->getPushedAt()->getTimestamp(),
            ], UrlGeneratorInterface::ABSOLUTE_URL),
        ]];
        if ($handover->hasMuteActions()) {
            foreach ($push->muteActions as $action => $title) {
                $actions[] = ['action' => $action, 'title' => $title];
            }
        }

        $data = [
            'video' => '/media/local/'.$handover->getFileBase().'.mp4',
            'group' => $handover->getGroup(),
            'tag' => $handover->getTag(),
            'push' => ['interruption-level' => $event->isUnusual() && !$event->isFalseAlarm() ? $push->loudInterruptionLevel : 'passive'],
            'actions' => $actions,
        ];
        if (null !== $push->url) {
            $data['url'] = $push->url;
        }

        return [
            'title' => $handover->getTitle(),
            'message' => trim($handover->getMessage().' '.$event->getSummary()),
            'data' => $data,
        ];
    }
}
