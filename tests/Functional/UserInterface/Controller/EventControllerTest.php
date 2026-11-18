<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Domain\Model\Confidence;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\EventController;
use App\UserInterface\Controller\ReviewController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(EventController::class)]
#[CoversClass(ReviewController::class)]
final class EventControllerTest extends WebTestCase
{
    use ResetsStorage;
    use StoresEvents;

    public function testShowsTheEventWithItsFrames(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A grey cat drags the doormat.');
        $this->storeEvents(self::getContainer(), $event);

        $crawler = $client->request('GET', '/events/'.$event->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Terrace');
        self::assertSelectorTextContains('.facts', 'A grey cat drags the doormat.');
        self::assertSelectorTextContains('.facts', 'Other cat', 'Luna is a known cat in the test settings; this one is not her.');
        self::assertSame('/events/'.$event->getId().'/frames/0.jpg?v='.$event->getStartedAt()->format('U'), $crawler->filter('.frames img')->attr('src'));
    }

    public function testShowsThatARepeatWasNotSentToTheAi(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $checked = CameraEventFactory::described('A spider web in front of the lens.', '2026-11-06 03:00:00', verdict: Verdict::FalseAlarm, subject: 'nothing', falseAlarmCause: 'spider_web');
        $this->storeEvents(self::getContainer(), $checked);
        $repeat = CameraEventFactory::withFrames('2026-11-06 03:10:00');
        $repeat->describeAsRepeatOf($checked, CameraEventFactory::utc('2026-11-06 03:11:00'));
        $this->storeEvents(self::getContainer(), $repeat);

        $crawler = $client->request('GET', '/events/'.$repeat->getId());

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.described-by', 'Not sent to the AI');
        self::assertSame('/events/'.$checked->getId(), $crawler->filter('.described-by a')->attr('href'));
    }

    public function testSavesALabelAndAVerdictOverride(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('Something moves.', verdict: Verdict::Real);
        $this->storeEvents(self::getContainer(), $event);
        $id = $event->getId();

        $client->request('GET', '/events/'.$id);
        $client->submitForm('Save', ['label[label]' => 'the postman', 'label[verdictOverride]' => 'false_alarm', 'label[falseAlarmCauseOverride]' => 'light']);

        self::assertResponseRedirects('/events/'.$id, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Saved.');

        $saved = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $id]);
        self::assertSame('the postman', $saved->getLabel());
        self::assertSame(Verdict::FalseAlarm, $saved->getEffectiveVerdict());
        self::assertSame('light', $saved->getEffectiveFalseAlarmCause());
    }

    public function testConfirmsAnUnsureAnswerFromTheCheckList(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('Something dark at the hedge.', confidence: Confidence::Low);
        $this->storeEvents(self::getContainer(), $event);
        $id = $event->getId();

        $client->request('GET', '/check');
        self::assertSelectorTextContains('nav', 'Check 1');
        self::assertSelectorTextContains('.cards', 'Something dark at the hedge.');
        $client->submitForm('Looks right');

        self::assertResponseRedirects('/check', 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.empty', 'Nothing to check.');
        $saved = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $id]);
        self::assertNotNull($saved->getReviewedAt());
        self::assertFalse($saved->isCorrected());
    }

    public function testRejectsAConfirmationWithoutToken(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A shape.', confidence: Confidence::Low);
        $this->storeEvents(self::getContainer(), $event);

        $client->request('POST', '/events/'.$event->getId().'/confirm');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCorrectsTheIndividual(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A cat.');
        $this->storeEvents(self::getContainer(), $event);

        $client->request('GET', '/events/'.$event->getId());
        $client->submitForm('Save', ['label[individualOverride]' => 'luna']);
        $client->followRedirect();

        self::assertSelectorTextContains('.facts', 'Luna set by hand; the AI said nobody we know');
    }

    public function testSavesTheDraggedBoxAsAReferencePhoto(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A cat.');
        $this->storeEvents(self::getContainer(), $event);
        $id = $event->getId();

        $client->request('GET', '/events/'.$id);
        $client->submitForm('Save reference photo', ['reference[frame]' => '0', 'reference[individual]' => 'luna', 'reference[crop]' => '0.5,0,0.5,1']);

        self::assertResponseRedirects('/events/'.$id, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Saved as a reference photo of Luna.');
        $photos = self::getContainer()->get(ReferencePhotoStoreInterface::class)->findBy('luna');
        self::assertCount(1, $photos);
        self::assertSame([4, 4], $this->imageSize($photos[0]->path), 'The right half of the 8x4 frame.');
        self::assertSame('luna', self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $id])->getEffectiveIndividual());
    }

    public function testRejectsABoxOutsideTheFrame(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A cat.');
        $this->storeEvents(self::getContainer(), $event);

        $client->request('GET', '/events/'.$event->getId());
        $client->submitForm('Save reference photo', ['reference[frame]' => '0', 'reference[individual]' => 'luna', 'reference[crop]' => '0.8,0,0.5,1']);

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], self::getContainer()->get(ReferencePhotoStoreInterface::class)->findBy('luna'));
    }

    public function testRejectsALabelWithoutCsrfToken(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('Something moves.');
        $this->storeEvents(self::getContainer(), $event);

        $client->request('POST', '/events/'.$event->getId(), ['label' => ['label' => 'x']]);

        self::assertResponseStatusCodeSame(422);
        self::assertNull(self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $event->getId()])->getLabel());
    }

    public function testServesFramesAndRejectsUnknownOnes(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('A grey cat.');
        $this->storeEvents(self::getContainer(), $event);

        $client->request('GET', '/events/'.$event->getId().'/frames/0.jpg');
        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'image/jpeg');

        $client->request('GET', '/events/'.$event->getId().'/frames/2.jpg');
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{int, int}
     */
    private function imageSize(string $path): array
    {
        $size = getimagesize($path);
        self::assertNotFalse($size);

        return [$size[0], $size[1]];
    }
}
