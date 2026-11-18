<?php

declare(strict_types=1);

namespace App\UserInterface\Mcp;

use App\Application\Frame\FrameStorageInterface;
use App\Application\Search\EventSearchInterface;
use App\Application\Search\SearchCriteria;
use App\Domain\Model\Camera;
use App\Domain\Repository\CameraEventRepositoryInterface;
use App\Domain\Repository\CameraRepositoryInterface;
use InvalidArgumentException;
use Mcp\Capability\Attribute\McpTool;
use Mcp\Capability\Attribute\Schema;
use Mcp\Schema\Content\Content;
use Mcp\Schema\Content\ImageContent;
use Mcp\Schema\Content\TextContent;
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
        private TimeParser $timeParser,
        private FrameStorageInterface $frameStorage,
    ) {
    }

    /**
     * @return array{total: int, events: list<array<string, mixed>>}
     */
    #[McpTool(
        name: 'search_camera_events',
        description: 'Search the stored events of the home cameras (list_cameras names them), newest first. Each event has a description, a verdict (real or false_alarm), the subject and the known individual (list_known_individuals), the side (our_property or outside), hints from the home and a link to its frames. "total" counts all matches beyond the limit. Times are local. For counts over days, camera_activity is exact.',
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
        #[Schema(description: 'Subject name, e.g. person or cat; empty for any')]
        string $subject = '',
        #[Schema(description: 'Name of a known individual from list_known_individuals, e.g. luna; empty for anyone')]
        string $individual = '',
    ): array {
        $result = $this->search->search(new SearchCriteria(
            text: $query,
            cameras: '' === $camera ? [] : [$this->cameras->findOneBy(['slug' => $camera])?->getSlug() ?? throw new InvalidArgumentException(\sprintf('Unknown camera "%s".', $camera))],
            from: $this->timeParser->parse($from),
            to: $this->timeParser->parse($to),
            includeFalseAlarms: $includeFalseAlarms,
            limit: max(1, min(self::MAX_LIMIT, $limit)),
            subjects: '' === $subject ? [] : [$subject],
            individuals: '' === $individual ? [] : [$individual],
        ));

        return ['total' => $result->total, 'events' => array_map($this->presenter->present(...), $result->events)];
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
     * @return list<Content>
     */
    #[McpTool(
        name: 'get_camera_event',
        description: 'Get one stored camera event by its id, with its frames as images, so you can look at what the camera saw yourself.',
        annotations: new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
    )]
    public function get(
        int $id,
        #[Schema(description: 'Attach the frames (up to three JPEG images)')]
        bool $includeFrames = true,
    ): array {
        $event = $this->events->findOneBy(['id' => $id]) ?? throw new InvalidArgumentException(\sprintf('Event %d does not exist.', $id));
        $content = [new TextContent(json_encode($this->presenter->present($event), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR))];
        if (!$includeFrames) {
            return $content;
        }

        foreach ($event->getFramePaths() as $framePath) {
            $path = $this->frameStorage->absolutePath($framePath);
            if (is_file($path)) {
                $content[] = ImageContent::fromFile($path, 'image/jpeg');
            }
        }

        return $content;
    }
}
