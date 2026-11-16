<?php

declare(strict_types=1);

namespace CameraEvents\HomeAssistant\Infrastructure\HomeAssistant;

use App\Application\Description\EventProcessor;
use App\Application\Frame\FootageNotFoundException;
use App\Application\Frame\FrameExtractorInterface;
use App\Application\Frame\FrameSourceInterface;
use App\Domain\Model\CameraEvent;
use CameraEvents\HomeAssistant\Application\Import\EventGrouper;
use CameraEvents\HomeAssistant\Application\Push\EventHandovers;
use CameraEvents\HomeAssistant\Application\Settings\PushSettings;
use CameraEvents\HomeAssistant\Application\Settings\SettingsProviderInterface;
use CameraEvents\HomeAssistant\Infrastructure\SurveillanceStation\RecordingFrameSource;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Frames for events Home Assistant sent a push for, from the push's snapshot and clip; seconds after the detection,
 * long before the continuous recording is readable. Falls back to the recording when the files are gone.
 */
#[AsTaggedItem(priority: 10)]
final readonly class ClipFrameSource implements FrameSourceInterface
{
    /**
     * Per frame: the file and the position in it. The snapshot is taken at the detection; the clip starts about four
     * seconds later.
     */
    private const array CUTS = [['jpg', null], ['mp4', 0.5], ['mp4', 3.5]];

    public function __construct(
        private SettingsProviderInterface $settings,
        private EventHandovers $handovers,
        private FrameExtractorInterface $extractor,
        private RecordingFrameSource $recordings,
        #[Target('home_assistant.client')]
        private HttpClientInterface $client,
        private LoggerInterface $logger,
    ) {
    }

    public function supports(CameraEvent $event): bool
    {
        return EventGrouper::SOURCE === $event->getSource()
            && $this->settings->settings()->push instanceof PushSettings
            && [] !== $this->handovers->forEvent($event);
    }

    public function grab(CameraEvent $event, DateTimeImmutable $at, string $targetPath): void
    {
        $handover = $this->handovers->forEvent($event)[0] ?? throw new FootageNotFoundException('The push handover is gone.');
        $index = array_search($at->getTimestamp() - $event->getStartedAt()->getTimestamp(), EventProcessor::FRAME_OFFSETS_SECONDS, true);
        [$extension, $position] = self::CUTS[false === $index ? 0 : $index];

        $file = $this->download($handover->getFileBase().'.'.$extension);
        if (null === $file) {
            $this->fallBackToRecording($event, $at, $targetPath);

            return;
        }

        try {
            $this->extractor->extract($file, $position, $targetPath);
        } finally {
            new Filesystem()->remove($file);
        }
    }

    private function download(string $mediaPath): ?string
    {
        try {
            $response = $this->client->request('GET', '/media/local/'.$mediaPath);
            if (200 !== $response->getStatusCode()) {
                $this->logger->info('Home Assistant has no media file {path} ({status}).', ['path' => $mediaPath, 'status' => $response->getStatusCode()]);

                return null;
            }

            $file = new Filesystem()->tempnam(sys_get_temp_dir(), 'camera-events-push-', '.'.pathinfo($mediaPath, \PATHINFO_EXTENSION));
            new Filesystem()->dumpFile($file, $response->getContent());
        } catch (ExceptionInterface $exception) {
            $this->logger->warning('Could not fetch {path} from Home Assistant: {message}', ['path' => $mediaPath, 'message' => $exception->getMessage()]);

            return null;
        }

        return $file;
    }

    private function fallBackToRecording(CameraEvent $event, DateTimeImmutable $at, string $targetPath): void
    {
        if (!$this->recordings->supports($event)) {
            throw new FootageNotFoundException('The push files are gone and the camera has no recordings.');
        }

        $this->recordings->grab($event, $at, $targetPath);
    }
}
