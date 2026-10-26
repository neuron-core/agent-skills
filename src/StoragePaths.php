<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use RuntimeException;

/** Validate storage identifiers and literal resource paths without URI conversion. */
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

    private function isSingleSegment(string $identifier): bool
    {
        return $identifier !== '' && $identifier !== '.' && $identifier !== '..'
            && !str_contains($identifier, '/') && !str_contains($identifier, "\0");
    }
}
