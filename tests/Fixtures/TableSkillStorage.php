<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tests\Fixtures;

use InvalidArgumentException;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use NeuronAI\AgentSkills\StoragePaths;
use RuntimeException;

/** In-memory equivalent of UNIQUE(skill_identifier, path) in a single resource table. */
class TableSkillStorage implements SkillStorageInterface
{
    /** @var array<string, array<string, string>> */
    private array $resources = [];
    private StoragePaths $storagePaths;

    /** @param list<array{skill_identifier: string, path: string, content: string}> $rows */
    public function __construct(array $rows)
    {
        $this->storagePaths = new StoragePaths();
        foreach ($rows as $row) {
            $skillIdentifier = $row['skill_identifier'];
            $this->storagePaths->validateIdentifier($skillIdentifier);
            if (isset($this->resources[$skillIdentifier][$row['path']])) {
                throw new InvalidArgumentException('Duplicate skill_identifier and path.');
            }
            $this->resources[$skillIdentifier][$row['path']] = $row['content'];
        }
    }

    public function list(): array
    {
        return array_map(strval(...), array_keys($this->resources));
    }

    public function resources(string $skillIdentifier): array
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);
        $paths = array_keys($this->resources[$skillIdentifier]
            ?? throw new RuntimeException(sprintf('Skill "%s" is not available.', $skillIdentifier)));
        sort($paths, SORT_STRING);

        return $paths;
    }

    public function read(string $skillIdentifier, string $path): string
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);
        $path = $this->storagePaths->normalizePath($path);

        return $this->resources[$skillIdentifier][$path]
            ?? throw new RuntimeException(sprintf('Database resource "%s" is unavailable in "%s".', $path, $skillIdentifier));
    }
}
