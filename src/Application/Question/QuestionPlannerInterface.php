<?php

declare(strict_types=1);

namespace App\Application\Question;

use App\Application\Ai\AiRequestFailedException;
use App\Application\Search\SearchCriteria;

interface QuestionPlannerInterface
{
    /**
     * Turns a question such as "When was the grey cat here?" into search criteria.
     *
     * @throws AiRequestFailedException
     */
    public function plan(string $question, int $limit): SearchCriteria;
}
