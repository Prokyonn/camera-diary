<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Api;

use App\Application\Description\PendingEventRunner;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\EventStatus;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Infrastructure\Media\FfmpegFrameExtractor;
use App\Infrastructure\Security\IngestTokenHandler;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Api\IngestController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

#[CoversClass(IngestController::class)]
#[CoversClass(IngestTokenHandler::class)]
#[CoversClass(FfmpegFrameExtractor::class)]
final class IngestControllerTest extends WebTestCase
{
    use ResetsStorage;

    private const array AUTH = ['HTTP_AUTHORIZATION' => 'Bearer test-ingest-token'];

    private KernelBrowser $client;

    private string $mediaDir;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();
        $this->resetStorage(self::getContainer());
        $this->mediaDir = sys_get_temp_dir().'/camera-diary-media-'.bin2hex(random_bytes(4));
        new Filesystem()->mkdir($this->mediaDir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->mediaDir);
        parent::tearDown();
    }

    public function testStoresAVideoEventThatTheWorkerDescribes(): void
    {
        $this->client->request('POST', '/api/events', [
            'camera' => 'front-door',
            'cameraName' => 'Front door',
            'startedAt' => '2026-11-10T07:16:20+01:00',
            'detections' => ['person'],
            'hints' => ['Doorbell rang'],
        ], ['video' => $this->video()], self::AUTH);

        self::assertResponseStatusCodeSame(201);
        $response = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($response);
        self::assertSame('pending', $response['status'] ?? null);
        self::assertIsInt($response['id'] ?? null);
        self::assertSame(\sprintf('http://localhost/events/%d', $response['id']), $response['url'] ?? null);

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['cameraSlug' => 'front-door']);
        self::assertSame('Front door', $event->getCamera()->getName());
        self::assertSame('2026-11-10 06:16:20', $event->getStartedAt()->format('Y-m-d H:i:s'));
        self::assertCount(3, $event->getFramePaths());
        self::assertSame([320, 180], [$event->getFrameWidth(), $event->getFrameHeight()]);

        self::getContainer()->get(FakePlatform::class)->respondWith(
            '{"verdict":"real","falseAlarmCause":"none","side":"our_property","subject":"person","individual":"unknown","summary":"A courier in a yellow jacket leaves a parcel at the door.","unusual":false}',
        );
        self::assertSame(['described' => 1], self::getContainer()->get(PendingEventRunner::class)->run());
        self::assertSame(EventStatus::Described, $event->getStatus());
        $found = self::getContainer()->get(EventSearchInterface::class)->search(new SearchCriteria(text: 'courier parcel'));
        self::assertSame($event->getId(), ($found->events[0] ?? null)?->getId());
    }

    public function testStoresImagesAndAnswersARepeatWithTheStoredEvent(): void
    {
        $parameters = ['camera' => 'garden', 'externalId' => 'frigate-1699600000.123-abc', 'source' => 'frigate'];

        $this->client->request('POST', '/api/events', $parameters, ['images' => [$this->image('a.png'), $this->image('b.jpg')]], self::AUTH);
        self::assertResponseStatusCodeSame(201);

        $this->client->request('POST', '/api/events', $parameters, ['images' => [$this->image('a.png')]], self::AUTH);
        self::assertResponseStatusCodeSame(200);

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['source' => 'frigate']);
        self::assertCount(2, $event->getFramePaths());
        self::assertSame(1, self::getContainer()->get(CameraEventRepositoryInterface::class)->countBy());
    }

    public function testReadsATimeWithoutOffsetInTheHomeTimeZone(): void
    {
        $this->client->request('POST', '/api/events', ['camera' => 'garden', 'startedAt' => '2026-11-10T07:16:20'], ['images' => [$this->image('a.png')]], self::AUTH);
        self::assertResponseStatusCodeSame(201);

        $event = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['cameraSlug' => 'garden']);
        self::assertSame('2026-11-10 06:16:20', $event->getStartedAt()->format('Y-m-d H:i:s'), 'Europe/Vienna in the test environment.');
    }

    public function testRejectsAMissingOrWrongToken(): void
    {
        $this->client->request('POST', '/api/events', ['camera' => 'garden'], ['images' => [$this->image('a.png')]]);
        self::assertResponseStatusCodeSame(401);

        $this->client->request('POST', '/api/events', ['camera' => 'garden'], ['images' => [$this->image('a.png')]], ['HTTP_AUTHORIZATION' => 'Bearer guess']);
        self::assertResponseStatusCodeSame(401);
    }

    public function testRejectsInvalidRequests(): void
    {
        $this->client->request('POST', '/api/events', ['camera' => 'garden'], [], self::AUTH);
        self::assertResponseStatusCodeSame(422, 'No media.');

        $this->client->request('POST', '/api/events', ['camera' => 'Front Door'], ['images' => [$this->image('a.png')]], self::AUTH);
        self::assertResponseStatusCodeSame(422, 'Invalid camera slug.');

        $this->client->request('POST', '/api/events', ['camera' => 'garden', 'startedAt' => 'half past seven'], ['images' => [$this->image('a.png')]], self::AUTH);
        self::assertResponseStatusCodeSame(422, 'Unreadable time.');

        new Filesystem()->dumpFile($this->mediaDir.'/notes.mp4', 'not a video');
        $this->client->request('POST', '/api/events', ['camera' => 'garden'], ['video' => new UploadedFile($this->mediaDir.'/notes.mp4', 'notes.mp4', null, null, true)], self::AUTH);
        self::assertResponseStatusCodeSame(422, 'Unreadable video.');

        self::assertSame(0, self::getContainer()->get(CameraEventRepositoryInterface::class)->countBy());
    }

    private function video(): UploadedFile
    {
        $ffmpeg = new ExecutableFinder()->find('ffmpeg');
        if (null === $ffmpeg) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $path = $this->mediaDir.'/clip.mp4';
        new Process([$ffmpeg, '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', 'testsrc=size=640x360:rate=5:duration=4', '-pix_fmt', 'yuv420p', $path])->mustRun();

        return new UploadedFile($path, 'clip.mp4', null, null, true);
    }

    private function image(string $name): UploadedFile
    {
        if (null === new ExecutableFinder()->find('ffmpeg')) {
            self::markTestSkipped('ffmpeg is not installed.');
        }

        $image = imagecreatetruecolor(64, 48);
        self::assertNotFalse($image);
        $path = $this->mediaDir.'/'.$name;
        str_ends_with($name, '.png') ? imagepng($image, $path) : imagejpeg($image, $path);

        return new UploadedFile($path, $name, null, null, true);
    }
}
