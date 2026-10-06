<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use DirectoryIterator;
use RuntimeException;

use function array_key_exists;
use function array_keys;
use function file_get_contents;
use function is_dir;
use function is_file;
use function is_readable;
use function ksort;
use function preg_match;
use function realpath;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_starts_with;

use const DIRECTORY_SEPARATOR;
use const SORT_STRING;

class FileSystemSkillStorage implements SkillStorageInterface
{
    /** @var array<string, string> */
    protected array $skillDirectories = [];

    protected string $skillsRoot;
    protected string $mount;

    public function __construct(string $mount)
    {
        // Accept local absolute file URIs only. Decode the mount once; resource paths are native text.
        if (!str_starts_with($mount, 'file:///') || str_contains($mount, '?') || str_contains($mount, '#')
            || preg_match('/%(?![0-9a-fA-F]{2})/', $mount) === 1) {
            throw new RuntimeException('Skill mount must be a local absolute file URI.');
        }
        $root = rawurldecode(substr($mount, 7));
        if (str_contains($root, "\0") || str_contains($root, '\\') || str_starts_with($root, '//')) {
            throw new RuntimeException('Skill mount must be a local absolute file URI.');
        }
        $segments = [];
        foreach (explode('/', $root) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }
        $this->skillsRoot = '/'.implode('/', $segments);
        $this->mount = 'file:///'.($segments === [] ? '' : implode('/', array_map(rawurlencode(...), $segments)).'/');
        $this->discover();
    }

    public function list(): array
    {
        return array_keys($this->skillDirectories);
    }

    public function read(string $location, string $path): string
    {
        if (!array_key_exists($location, $this->skillDirectories)) {
            throw new RuntimeException(sprintf('Skill "%s" is not available.', $location));
        }
        if (!$this->validPath($path)) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }

        $skillDirectory = $this->skillDirectories[$location];
        $file = realpath($skillDirectory.'/'.$path);
        if ($file === false) {
            throw new RuntimeException(sprintf('Resource "%s" was not found in skill "%s".', $path, $location));
        }
        if ($file !== $skillDirectory && !$this->isWithin($file, $skillDirectory)) {
            throw new RuntimeException(sprintf('Resource "%s" escapes skill "%s".', $path, $location));
        }
        if (!is_file($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" is not a file.', $path, $location));
        }
        if (!is_readable($file)) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $location));
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            throw new RuntimeException(sprintf('Resource "%s" in skill "%s" could not be read.', $path, $location));
        }
        if (str_contains($contents, "\0") || preg_match('//u', $contents) !== 1) {
            throw new RuntimeException(
                sprintf('Resource "%s" in skill "%s" contains unsupported binary content.', $path, $location),
            );
        }

        return $contents;
    }

    protected function validPath(string $path): bool
    {
        return $path !== '' && !str_contains($path, "\0")
            && !str_starts_with($path, '/') && !str_starts_with($path, '\\')
            && preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) !== 1;
    }

    protected function discover(): void
    {
        if (!is_dir($this->skillsRoot)) {
            return;
        }

        foreach (new DirectoryIterator($this->skillsRoot) as $entry) {
            if ($entry->isDot() || !$entry->isDir()) {
                continue;
            }

            $directory = realpath($entry->getPathname());
            if ($directory !== false) {
                $this->skillDirectories[$this->mount.rawurlencode($entry->getFilename()).'/'] = $directory;
            }
        }

        ksort($this->skillDirectories, SORT_STRING);
    }

    protected function isWithin(string $path, string $directory): bool
    {
        return str_starts_with($path, rtrim($directory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }
}
