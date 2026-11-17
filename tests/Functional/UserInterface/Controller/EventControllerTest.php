<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\EventController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(EventController::class)]
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

    public function testSavesALabelAndAVerdictOverride(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::described('Something moves.', verdict: Verdict::Real);
        $this->storeEvents(self::getContainer(), $event);
        $id = $event->getId();

        $client->request('GET', '/events/'.$id);
        $client->submitForm('Save', ['label[label]' => 'the postman', 'label[verdictOverride]' => 'false_alarm']);

        self::assertResponseRedirects('/events/'.$id, 303);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash', 'Saved.');

        $saved = self::getContainer()->get(CameraEventRepositoryInterface::class)->getOneBy(['id' => $id]);
        self::assertSame('the postman', $saved->getLabel());
        self::assertSame(Verdict::FalseAlarm, $saved->getEffectiveVerdict());
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
}
