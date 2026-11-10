<?php

declare(strict_types=1);

namespace App\UserInterface\Controller;

use App\Application\Question\QuestionAnswerer;
use App\UserInterface\Form\QuestionData;
use App\UserInterface\Form\QuestionType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AskController extends AbstractController
{
    public function __construct(
        private readonly QuestionAnswerer $answerer,
    ) {
    }

    #[Route('/ask', name: 'ask', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $question = new QuestionData();
        $form = $this->createForm(QuestionType::class, $question);
        $form->handleRequest($request);

        $result = null;
        if ($form->isSubmitted() && $form->isValid() && null !== $question->question) {
            $result = $this->answerer->answer($question->question);
        }

        return $this->render('ask/index.html.twig', [
            'form' => $form,
            'question' => $question->question,
            'result' => $result,
        ]);
    }
}
