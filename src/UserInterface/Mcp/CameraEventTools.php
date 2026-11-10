<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Clock\HomeTimeZone;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\ToolAnnotations;

/**
 * Tools for assistants such as Home Assistant Assist. The assistant's own model does the reasoning; these return stored events.
 */
final readonly class CameraEventTools
{
    private const int MAX_LIMIT = 25;

    public function __construct(
        private EventSearchInterface $search,
        private CameraEventRepositoryInterface $events,
        private CameraRepositoryInterface $cameras,
        private EventPresenter $presenter,
        private HomeTimeZone $homeTimeZone,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    #[McpTool(
        name: 'search_camera_events',
        description: 'Search the stored events of the home cameras (list_cameras names them). Each event has a description, a verdict (real or false_alarm), the side (our_property or outside), hints from the home and a link to its frames. Times are local.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function search(
        #[Schema(description: 'English keywords, e.g. "grey cat" or "person"; empty to list events by time')]
        string $query = '',
        #[Schema(description: 'Camera slug from list_cameras; empty for all cameras')]
        string $camera = '',
        #[Schema(description: 'Start of the time range, ISO 8601 (local time if no offset); empty for no limit')]
        string $from = '',
        #[Schema(description: 'End of the time range, ISO 8601 (local time if no offset); empty for no limit')]
        string $to = '',
        #[Schema(description: 'Also return false alarms such as spider webs or insects')]
        bool $includeFalseAlarms = false,
        #[Schema(description: 'Maximum number of events', minimum: 1, maximum: self::MAX_LIMIT)]
        int $limit = 10,
    ): array {
        $events = $this->search->search(new SearchCriteria(
            text: $query,
            cameras: '' === $camera ? [] : [$this->cameras->findOneBy(['slug' => $camera])?->getSlug() ?? throw new InvalidArgumentException(\sprintf('Unknown camera "%s".', $camera))],
            from: $this->parseTime($from),
            to: $this->parseTime($to),
            includeFalseAlarms: $includeFalseAlarms,
            limit: max(1, min(self::MAX_LIMIT, $limit)),
        ));

        return array_map($this->presenter->present(...), $events);
    }

    /**
     * @return list<array{slug: string, name: string, scene: string|null}>
     */
    #[McpTool(
        name: 'list_cameras',
        description: 'List the home cameras with their slug, name and what they see.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function listCameras(): array
    {
        return array_map(
            static fn (Camera $camera): array => ['slug' => $camera->getSlug(), 'name' => $camera->getName(), 'scene' => $camera->getScene()],
            $this->cameras->findBy([], ['name' => 'asc']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    #[McpTool(
        name: 'get_camera_event',
        description: 'Get one stored camera event by its id.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function get(int $id): array
    {
        $event = $this->events->findOneBy(['id' => $id]) ?? throw new InvalidArgumentException(\sprintf('Event %d does not exist.', $id));

        return $this->presenter->present($event);
    }

    private function parseTime(string $value): ?DateTimeImmutable
    {
        if ('' === trim($value)) {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->homeTimeZone->zone())->setTimezone(new DateTimeZone('UTC'));
        } catch (Exception $exception) {
            throw new InvalidArgumentException(\sprintf('"%s" is not a valid time.', $value), 0, $exception);
        }
    }
}
