<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use NeuronAI\AgentSkills\StoragePaths;

use FilesystemIterator;
use RuntimeException;

class FileSystemSkillStorage implements LocalSkillStorageInterface
{
    private string $baseDir;
    private StoragePaths $storagePaths;

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
        $this->storagePaths = new StoragePaths();
    }

    private function assertAbsoluteLocalPath(string $path): void
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')
            || strpbrk($path, "\0\\") !== false) {
            throw new RuntimeException('Skill directory must be an absolute local path.');
        }
    }

    public function list(): array
    {
        $identifiers = [];
        foreach (new FilesystemIterator($this->baseDir) as $entry) {
            if ($entry->isDir()) {
                $identifiers[] = $entry->getFilename();
            }
        }

        sort($identifiers, SORT_STRING);

        return $identifiers;
    }

    public function directory(string $skillIdentifier): string
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);
        $directory = realpath($this->baseDir.'/'.$skillIdentifier);
        if ($directory === false || !is_dir($directory)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $skillIdentifier));
        }

        return $directory;
    }

    public function resources(string $skillIdentifier): array
    {
        $directory = $this->directory($skillIdentifier);

        $paths = $this->collectResources($skillIdentifier, $directory, '', []);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param list<string> $ancestors
     * @return list<string>
     */
    private function collectResources(string $skillIdentifier, string $root, string $relative, array $ancestors): array
    {
        $directory = $relative === '' ? $root : $root.'/'.$relative;
        $canonical = realpath($directory);
        if ($canonical === false || in_array($canonical, $ancestors, true)) {
            return [];
        }

        $ancestors[] = $canonical;
        $paths = [];

        foreach (new FilesystemIterator($directory) as $entry) {
            $path = $relative === '' ? $entry->getFilename() : $relative.'/'.$entry->getFilename();
            $this->storagePaths->normalizePath($path);
            $target = realpath($entry->getPathname());
            if ($target === false) {
                throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skillIdentifier));
            }

            if ($target !== $root && !str_starts_with($target, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
                throw new RuntimeException(sprintf('Resource "%s" escapes skill "%s".', $path, $skillIdentifier));
            }

            if ($entry->isDir()) {
                array_push($paths, ...$this->collectResources($skillIdentifier, $root, $path, $ancestors));
            } elseif ($entry->isFile()) {
                $paths[] = $path;
            } else {
                throw new RuntimeException(sprintf('Resource "%s" in skill "%s" is not a file.', $path, $skillIdentifier));
            }
        }

        return $paths;
    }

    public function read(string $skillIdentifier, string $path): string
    {
        $this->storagePaths->validateIdentifier($skillIdentifier);
        $path = $this->storagePaths->normalizePath($path);

        $directory = $this->directory($skillIdentifier);

        $file = realpath($directory.'/'.$path);
        if ($file === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $skillIdentifier));
        }

        if (!str_starts_with($file, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException(sprintf('Resource "%s" escapes skill "%s".', $path, $skillIdentifier));
        }

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" is not a file.', $path, $skillIdentifier));
        }

        $contents = @file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $skillIdentifier));
        }

        return $contents;
    }
}
