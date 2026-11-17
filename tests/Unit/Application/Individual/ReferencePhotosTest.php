<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Individual;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Individual\CropBox;
use App\Application\Individual\InvalidReferencePhotoException;
use App\Application\Individual\ReferencePhoto;
use App\Application\Individual\ReferencePhotos;
use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\PhpUnit\ProphecyTrait;
use Prophecy\Prophecy\ObjectProphecy;

#[CoversClass(ReferencePhotos::class)]
final class ReferencePhotosTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    /** @var ObjectProphecy<ReferencePhotoStoreInterface> */
    private ObjectProphecy $store;

    /** @var ObjectProphecy<FrameStorageInterface> */
    private ObjectProphecy $frameStorage;

    protected function setUp(): void
    {
        CameraFactory::reset();
        $this->store = $this->prophesize(ReferencePhotoStoreInterface::class);
        $this->frameStorage = $this->prophesize(FrameStorageInterface::class);
    }

    public function testGivesTheAiThePhotosOfTheIndividualsForTheDetectionTypes(): void
    {
        $this->store->findBy('luna')->willReturn([new ReferencePhoto('/data/luna/new.jpg', 'new.jpg'), new ReferencePhoto('/data/luna/old.jpg', 'old.jpg')]);
        $this->store->findBy('anna')->shouldNotBeCalled();

        $references = $this->referencePhotos(['reference_photos' => 2])->forEvent(CameraEventFactory::create(type: 'animal'));

        self::assertCount(1, $references);
        self::assertSame('luna', $references[0]->individual->name);
        self::assertSame([__FILE__, '/data/luna/new.jpg'], $references[0]->photos, 'The settings\' photos first, then the newest added, at most ai.reference_photos.');
    }

    public function testLeavesOutIndividualsWithoutPhotos(): void
    {
        $this->store->findBy('anna')->willReturn([]);

        self::assertSame([], $this->referencePhotos()->forEvent(CameraEventFactory::create(type: 'person')));
    }

    public function testStoresTheCroppedFrameOfAnEvent(): void
    {
        $event = CameraEventFactory::withFrames(id: 1);
        $crop = new CropBox(0.5, 0, 0.5, 1);
        $this->frameStorage->absolutePath($event->getFramePaths()[0] ?? '')->willReturn('/frames/frame-0.jpg');
        $this->store->add('anna', '/frames/frame-0.jpg', $crop)->willReturn(new ReferencePhoto('/data/anna/x.jpg', 'x.jpg'))->shouldBeCalledOnce();

        $anna = $this->settingsProvider($this->config())->settings()->individual('anna') ?? self::fail('Anna is missing.');
        $this->referencePhotos()->addFromFrame($event, 0, $anna, $crop);
    }

    public function testRejectsAFrameTheEventDoesNotHave(): void
    {
        $anna = $this->settingsProvider($this->config())->settings()->individual('anna') ?? self::fail('Anna is missing.');

        $this->expectException(InvalidReferencePhotoException::class);

        $this->referencePhotos()->addFromFrame(CameraEventFactory::withFrames(id: 1), 2, $anna, null);
    }

    /**
     * @param array<string, mixed> $ai
     */
    private function referencePhotos(array $ai = []): ReferencePhotos
    {
        return new ReferencePhotos($this->settingsProvider($this->config($ai)), $this->store->reveal(), $this->frameStorage->reveal());
    }

    /**
     * @param array<string, mixed> $ai
     *
     * @return array<string, mixed>
     */
    private function config(array $ai = []): array
    {
        return [
            'ai' => $ai,
            'individuals' => [
                'luna' => ['subject' => 'cat', 'photos' => [__FILE__], 'detection_types' => ['animal']],
                'anna' => ['subject' => 'person', 'detection_types' => ['person']],
            ],
        ];
    }
}
