<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

/** String-only normalization for resource keys relative to a skill root. */
final class ResourcePath
{
    /**
     * Resolve dot segments without accessing the filesystem or URI-decoding the path.
     * Filesystem adapters must resolve symlinks and check confinement separately.
     *
     * @throws RuntimeException If the path is invalid, absolute, escapes the root or names the root itself.
     */
    public static function normalize(string $path): string
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
}
