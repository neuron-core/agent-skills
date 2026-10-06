<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use NeuronAI\AgentSkills\ResourceLocator;

use FilesystemIterator;
use RuntimeException;

class FileSystemSkillStorage implements SkillStorageInterface
{
    private string $baseDir;
    private ResourceLocator $resourceLocator;

    public function __construct(string $base)
    {
        if (str_starts_with($base, 'file://')) {
            $base = rawurldecode(substr($base, 7));
        }

        $this->assertAbsoluteLocalPath($base);

        $directory = realpath($base);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException('Skill directory must exist and be a directory.');
        }

        $this->baseDir = $directory;
        $this->resourceLocator = new ResourceLocator($this->baseUriFromDirectory($directory));
    }

    private function assertAbsoluteLocalPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || strpbrk($path, "\0\\") !== false) {
            throw new RuntimeException('Skill directory must be an absolute local path.');
        }
    }

    private function baseUriFromDirectory(string $directory): string
    {
        $segments = explode('/', rtrim($directory, '/'));

        return 'file://'.implode('/', array_map(rawurlencode(...), $segments)).'/';
    }

    public function list(): array
    {
        $locations = [];
        foreach (new FilesystemIterator($this->baseDir) as $entry) {
            if ($entry->isDir()) {
                $locations[] = $this->resourceLocator->fromSkillIdentifier($entry->getFilename());
            }
        }

        sort($locations, SORT_STRING);

        return $locations;
    }

    public function read(string $location, string $path): string
    {
        $resource = $this->resourceLocator->resolve($location, $path);

        $directory = realpath($this->baseDir.'/'.$resource->skillIdentifier);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $location));
        }

        $file = realpath($directory.'/'.$resource->path);
        if ($file === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $resource->path, $location));
        }

        if (!str_starts_with($file, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(sprintf('Resource "%s" escapes skill "%s".', $resource->path, $location));
        }

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" is not a file.', $resource->path, $location));
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $resource->path, $location));
        }

        return $contents;
    }
}
