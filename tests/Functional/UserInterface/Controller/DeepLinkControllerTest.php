<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Tests\Factory\CameraEventFactory;
use App\Tests\Factory\CameraFactory;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\DeepLinkController;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(DeepLinkController::class)]
final class DeepLinkControllerTest extends WebTestCase
{
    use ResetsStorage;
    use StoresEvents;

    public function testRedirectsToTheEvent(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $event = CameraEventFactory::create('2026-11-07 13:55:00', 'garden');
        $this->storeEvents(self::getContainer(), $event);

        $client->request('GET', '/at', ['camera' => 'garden', 't' => CameraEventFactory::utc('2026-11-07 13:55:03')->getTimestamp()]);

        self::assertResponseRedirects('/events/'.$event->getId());
    }

    public function testWaitsForAnEventThatIsNotImportedYet(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(CameraFactory::get('terrace'));
        $entityManager->flush();

        $client->request('GET', '/at', ['camera' => 'terrace', 't' => CameraEventFactory::utc('2026-11-07 09:40:00')->getTimestamp()]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Refresh', '30');
        self::assertSelectorTextContains('h1', 'Terrace, 10:40:00');
    }

    public function testRejectsAnUnknownCamera(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());

        $client->request('GET', '/at', ['camera' => 'kitchen', 't' => 1]);

        self::assertResponseStatusCodeSame(404);
    }
}
