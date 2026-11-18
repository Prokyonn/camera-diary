<?php

declare(strict_types=1);

namespace App\Tests\Functional\UserInterface\Controller;

use App\Tests\Factory\CameraEventFactory;
use App\Tests\Support\FakePlatform;
use App\Tests\Support\ResetsStorage;
use App\Tests\Support\StoresEvents;
use App\UserInterface\Controller\AskController;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

#[CoversClass(AskController::class)]
final class AskControllerTest extends WebTestCase
{
    use ResetsStorage;
    use StoresEvents;

    public function testAnswersAQuestionWithTheMatchingEvents(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->resetStorage(self::getContainer());
        $greyCat = CameraEventFactory::described('A grey cat drags the doormat from the cat flap to the door.');
        $this->storeEvents(self::getContainer(), $greyCat);

        $platform = self::getContainer()->get(FakePlatform::class);
        $platform->respondWith(
            '{"keywords":"grey cat","cameras":[],"from":"","to":"","side":"any","includeFalseAlarms":false}',
            \sprintf('{"text":"The grey cat was on the terrace on 6 November at 07:16.","eventIds":[%d]}', $greyCat->getId()),
        );

        $client->request('GET', '/ask');
        $client->submitForm('Ask', ['question[question]' => 'When was the strange grey cat here?']);
        self::assertResponseStatusCodeSame(303);
        $crawler = $client->followRedirect();

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.answer', 'The grey cat was on the terrace on 6 November at 07:16.');
        self::assertCount(1, $crawler->filter('.cards article'));
        self::assertCount(2, $platform->calls());

        $client->request('GET', $client->getRequest()->getUri());
        self::assertSelectorTextContains('.answer', 'The grey cat was on the terrace', 'An earlier answer is read again without the AI.');
        self::assertSelectorTextContains('.recent-questions', 'When was the strange grey cat here?');
        self::assertCount(2, self::getContainer()->get(FakePlatform::class)->calls(), 'No further AI call.');
    }

    public function testRequiresAQuestion(): void
    {
        $client = self::createClient();

        $client->request('GET', '/ask');
        $client->submitForm('Ask', ['question[question]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('.answer');
    }
}
