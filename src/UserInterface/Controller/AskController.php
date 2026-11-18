<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Question\QuestionAnswerer;
use App\Application\Question\QuestionHistory;
use App\Application\Question\QuestionResult;
use App\Domain\Model\AskedQuestion;
use App\UserInterface\Form\QuestionData;
use App\UserInterface\Form\QuestionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AskController extends AbstractController
{
    private const int RECENT = 10;

    public function __construct(
        private readonly QuestionAnswerer $answerer,
        private readonly QuestionHistory $history,
    ) {
    }

    #[Route('/ask', name: 'ask', methods: ['GET', 'POST'])]
    public function ask(Request $request): Response
    {
        $question = new QuestionData();
        $form = $this->createForm(QuestionType::class, $question);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== $question->question) {
            $asked = $this->history->record($question->question, $this->answerer->answer($question->question));

            return $this->redirectToRoute('ask_show', ['id' => $asked->getId()], Response::HTTP_SEE_OTHER);
        }

        return $this->page($form, null, null);
    }

    #[Route('/ask/{id:asked}', name: 'ask_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function show(AskedQuestion $asked): Response
    {
        $form = $this->createForm(QuestionType::class, new QuestionData($asked->getQuestion()), ['action' => $this->generateUrl('ask')]);

        return $this->page($form, $asked, $this->history->result($asked));
    }

    /**
     * @param FormInterface<QuestionData> $form
     */
    private function page(FormInterface $form, ?AskedQuestion $asked, ?QuestionResult $result): Response
    {
        return $this->render('ask/index.html.twig', [
            'form' => $form,
            'asked' => $asked,
            'result' => $result,
            'recent' => $this->history->recent(self::RECENT),
        ]);
    }
}
