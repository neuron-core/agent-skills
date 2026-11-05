<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use RuntimeException;

/**
 * Validate skill identifiers, literal resource paths and local directories.
 *
 * @internal
 */
final class StoragePaths
{
    /** @throws RuntimeException */
    public function validateIdentifier(string $skillIdentifier): void
    {
        if ($skillIdentifier === '') {
            throw new RuntimeException('Skill identifiers must be non-empty strings.');
        }

        if (!$this->isSingleSegment($skillIdentifier)) {
            throw new RuntimeException('Skill identifiers must be single path segments.');
        }
    }

    /** @throws RuntimeException */
    public function normalizePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || str_starts_with($path, '/') || preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $path) === 1) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    throw new RuntimeException(sprintf('Resource path "%s" escapes the skill root.', $path));
                }

                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        if ($segments === []) {
            throw new RuntimeException(sprintf('Resource path "%s" is invalid.', $path));
        }

        return implode('/', $segments);
    }

    /**
     * Whether a path is an absolute local path on the platform using the given directory separator:
     * "/srv/skills" on POSIX, "C:\srv\skills" or "C:/srv/skills" on Windows. Network paths are rejected.
     */
    public function isAbsoluteLocalPath(string $path, string $separator = DIRECTORY_SEPARATOR): bool
    {
        if (str_contains($path, "\0")) {
            return false;
        }

        if ($separator === '\\') {
            return preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1;
        }

        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\');
    }

    private function isSingleSegment(string $identifier): bool
    {
        return $identifier !== '' && $identifier !== '.' && $identifier !== '..'
            && strpbrk($identifier, "/\\\0") === false;
    }
}
