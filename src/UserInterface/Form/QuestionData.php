<?php

declare(strict_types=1);

namespace App\UserInterface\Form;

use Symfony\Component\Validator\Constraints as Assert;

final class QuestionData
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 500)]
        public ?string $question = null,
    ) {
    }
}
