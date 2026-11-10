<?php

declare(strict_types=1);

namespace App\Infrastructure\Seal;

use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\CameraEvent;
use App\Domain\Model\Side;
use App\Domain\Model\Verdict;
use App\Domain\Repository\CameraEventRepositoryInterface;
use CmsIg\Seal\EngineInterface;
use CmsIg\Seal\Search\Condition\Condition;
use DateTimeInterface;

final readonly class SealEventSearch implements EventSearchInterface
{
    /** Loupe nests one subquery per term; with filters, eight or more terms exceed SQLite's depth limit. */
    public const int MAX_TERMS = 6;

    private const array STOP_WORDS = [
        'and', 'any', 'are', 'but', 'came', 'can', 'did', 'does', 'for', 'from', 'had', 'has', 'have', 'how', 'into',
        'its', 'last', 'not', 'our', 'out', 'show', 'that', 'the', 'their', 'them', 'there', 'they', 'this', 'was',
        'were', 'what', 'when', 'where', 'which', 'who', 'why', 'with', 'you', 'your',
    ];

    public function __construct(
        private EngineInterface $engine,
        private CameraEventRepositoryInterface $events,
    ) {
    }

    public function search(SearchCriteria $criteria): array
    {
        $builder = $this->engine->createSearchBuilder(SealEventIndexer::INDEX)->limit(max(1, $criteria->limit));

        $text = self::keywords($criteria->text);
        if ('' !== $text) {
            $builder->addFilter(Condition::search($text));
        } else {
            $builder->addSortBy('startedAt', 'desc');
        }

        if ([] !== $criteria->cameras) {
            $builder->addFilter(Condition::in('camera', $criteria->cameras));
        }

        if ($criteria->from instanceof DateTimeInterface) {
            $builder->addFilter(Condition::greaterThanEqual('startedAt', $criteria->from->format(DateTimeInterface::ATOM)));
        }

        if ($criteria->to instanceof DateTimeInterface) {
            $builder->addFilter(Condition::lessThanEqual('startedAt', $criteria->to->format(DateTimeInterface::ATOM)));
        }

        if ($criteria->side instanceof Side) {
            $builder->addFilter(Condition::in('side', [$criteria->side->value, Side::Both->value]));
        }

        if (!$criteria->includeFalseAlarms) {
            $builder->addFilter(Condition::notEqual('verdict', Verdict::FalseAlarm->value));
        }

        $ids = [];
        foreach ($builder->getResult() as $document) {
            $id = $document['id'] ?? null;
            if (is_numeric($id)) {
                $ids[] = (int) $id;
            }
        }

        return $this->inOrder($ids, [] === $ids ? [] : $this->events->findBy(['ids' => $ids]));
    }

    /**
     * The first distinct words that carry meaning, e.g. "wild animals today property" for a whole question.
     */
    public static function keywords(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $words) {
            return '';
        }

        $keywords = array_filter(
            array_unique($words),
            static fn (string $word): bool => mb_strlen($word) >= 3 && !\in_array($word, self::STOP_WORDS, true),
        );

        return implode(' ', \array_slice($keywords, 0, self::MAX_TERMS));
    }

    /**
     * @param list<int>         $ids
     * @param list<CameraEvent> $events
     *
     * @return list<CameraEvent>
     */
    private function inOrder(array $ids, array $events): array
    {
        $byId = [];
        foreach ($events as $event) {
            $byId[$event->getId()] = $event;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $ordered[] = $byId[$id];
            }
        }

        return $ordered;
    }
}
