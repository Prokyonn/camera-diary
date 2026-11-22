<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Camera;

use App\Application\Camera\CameraRegistry;
use App\Application\Camera\CameraSettings;
use App\Application\Persistence\UnitOfWorkInterface;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraRepositoryInterface;
use App\Tests\Factory\CameraFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[CoversClass(CameraSettings::class)]
final class CameraSettingsTest extends TestCase
{
    use ProphecyTrait;

    protected function setUp(): void
    {
        CameraFactory::reset();
    }

    protected function tearDown(): void
    {
        CameraFactory::reset();
    }

    public function testCreatesAnUnknownCameraWithItsScene(): void
    {
        $cameras = $this->prophesize(CameraRepositoryInterface::class);
        $cameras->findOneBy(['slug' => 'porch'])->willReturn(null);
        $cameras->add(Argument::type(Camera::class))->shouldBeCalledOnce();

        $camera = $this->settings($cameras->reveal())->update('porch', scene: 'The porch');

        self::assertSame('porch', $camera->getSlug());
        self::assertSame('The porch', $camera->getScene());
    }

    public function testKeepsWhatIsNotGivenAndRemovesAnEmptyScene(): void
    {
        $garden = CameraFactory::get('garden');
        $garden->describeScene('The lawn');
        $cameras = $this->prophesize(CameraRepositoryInterface::class);
        $cameras->findOneBy(['slug' => 'garden'])->willReturn($garden);
        $cameras->add(Argument::any())->shouldNotBeCalled();
        $settings = $this->settings($cameras->reveal());

        $settings->update('garden', 'Back garden');
        self::assertSame('Back garden', $garden->getName());
        self::assertSame('The lawn', $garden->getScene());

        $settings->update('garden', scene: '');
        self::assertNull($garden->getScene());
    }

    private function settings(CameraRepositoryInterface $cameras): CameraSettings
    {
        $unitOfWork = $this->prophesize(UnitOfWorkInterface::class)->reveal();

        return new CameraSettings(new CameraRegistry($cameras, $unitOfWork, new LockFactory(new InMemoryStore())), $unitOfWork);
    }
}
