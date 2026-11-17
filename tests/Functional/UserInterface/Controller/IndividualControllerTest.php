<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Application\Individual\ReferencePhotoStoreInterface;
use App\Tests\Support\ResetsStorage;
use App\UserInterface\Controller\IndividualController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(IndividualController::class)]
final class IndividualControllerTest extends WebTestCase
{
    use ResetsStorage;

    public function testListsTheReferencePhotosAndRemovesAddedOnes(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $store = self::getContainer()->get(ReferencePhotoStoreInterface::class);
        $added = $store->add('luna', \dirname(__DIR__, 3).'/Fixtures/individuals/luna.jpg');

        $crawler = $client->request('GET', '/individuals');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2', 'Luna');
        self::assertCount(2, $crawler->filter('.reference-photos img'), 'The settings\' photo and the added one.');
        self::assertSelectorTextContains('.reference-photos', 'from the settings');

        $client->request('GET', '/individuals/luna/photos/1.jpg');
        self::assertResponseIsSuccessful();
        $client->request('GET', '/individuals/luna/photos/5.jpg');
        self::assertResponseStatusCodeSame(404);

        $client->request('GET', '/individuals');
        $client->submitForm('Remove');
        self::assertResponseRedirects('/individuals', 303);
        self::assertSame([], $store->findBy('luna'));
        self::assertFileDoesNotExist($added->path);
    }

    public function testRejectsARemovalWithoutCsrfToken(): void
    {
        $client = self::createClient();
        $this->resetStorage(self::getContainer());
        $store = self::getContainer()->get(ReferencePhotoStoreInterface::class);
        $added = $store->add('luna', \dirname(__DIR__, 3).'/Fixtures/individuals/luna.jpg');

        $client->request('POST', '/individuals/luna/photos/'.$added->storedId.'/remove');

        self::assertResponseStatusCodeSame(403);
        self::assertFileExists($added->path);
    }
}
