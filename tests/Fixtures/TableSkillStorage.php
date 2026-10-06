<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Fixtures;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use NeuronAI\AgentSkills\ResourceLocator;
use RuntimeException;

/** In-memory equivalent of UNIQUE(skill_identifier, path) in a single resource table. */
class TableSkillStorage implements SkillStorageInterface
{
    /** @var array<string, array<string, string>> */
    private array $resources = [];
    private ResourceLocator $resourceLocator;

    /** @param list<array{skill_identifier: string, path: string, content: string}> $rows */
    public function __construct(string $mount, array $rows)
    {
        if (preg_match('~^db://[a-z0-9-]+/(?:[a-z0-9-]+/)*$~D', $mount) !== 1) {
            throw new InvalidArgumentException('Expected a complete db:// mount ending in a slash.');
        }
        $this->resourceLocator = new ResourceLocator($mount);
        foreach ($rows as $row) {
            $location = $this->resourceLocator->fromSkillIdentifier($row['skill_identifier']);
            if (isset($this->resources[$location][$row['path']])) {
                throw new InvalidArgumentException('Duplicate skill_identifier and path.');
            }
            $this->resources[$location][$row['path']] = $row['content'];
        }
    }

    public function list(): array
    {
        return array_keys($this->resources);
    }

    public function read(string $location, string $path): string
    {
        $resource = $this->resourceLocator->resolve($location, $path);

        return $this->resources[$location][$resource->path]
            ?? throw new RuntimeException(sprintf('Database resource "%s" is unavailable in "%s".', $resource->path, $location));
    }
}
