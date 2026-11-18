<?php

declare(strict_types=1);

namespace App\Tests\Unit\Application\Description;

use App\Application\Description\PastCorrections;
use App\Domain\Model\EventStatus;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ProvidesSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Prophecy\Argument;
use Prophecy\PhpUnit\ProphecyTrait;

#[CoversClass(PastCorrections::class)]
final class PastCorrectionsTest extends TestCase
{
    use ProphecyTrait;
    use ProvidesSettings;

    public function testReturnsTheLatestCorrectionsOfTheCameraWithoutConfirmations(): void
    {
        $event = CameraEventFactory::withFrames(id: 1);
        $confirmed = CameraEventFactory::described('A cat.', id: 2);
        $first = CameraEventFactory::described('A person at the hedge.', id: 3);
        $first->relabel(null, Verdict::FalseAlarm, null);
        $second = CameraEventFactory::described('A dog.', id: 4);
        $second->relabel('a fox', null, null);
        $third = CameraEventFactory::described('A bird.', id: 5);
        $third->relabel('a bat', null, null);
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(['camera' => $event->getCamera(), 'status' => EventStatus::Described, 'reviewed' => true], ['reviewedAt' => 'desc'], Argument::any())
            ->willReturn([$confirmed, $first, $second, $third]);

        $corrections = new PastCorrections($events->reveal(), $this->settingsProvider(['ai' => ['correction_examples' => 2]]));

        self::assertSame([$first, $second], $corrections->forEvent($event));
    }

    public function testCanBeSwitchedOff(): void
    {
        $events = $this->prophesize(CameraEventRepositoryInterface::class);
        $events->findBy(Argument::cetera())->shouldNotBeCalled();

        self::assertSame([], new PastCorrections($events->reveal(), $this->settingsProvider(['ai' => ['correction_examples' => 0]]))->forEvent(CameraEventFactory::withFrames()));
    }
}
