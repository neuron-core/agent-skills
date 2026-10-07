<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use RuntimeException;

/** Locate skill resources under one base URI. */
final class ResourceLocator
{
    public function __construct(private string $baseUri)
    {
    }

    public function fromSkillIdentifier(string $identifier): string
    {
        if ($identifier === '') {
            throw new RuntimeException('Skill identifiers must be non-empty strings.');
        }

        if (!$this->isSingleSegment($identifier)) {
            throw new RuntimeException('Skill identifiers must be single path segments.');
        }

        return $this->baseUri.rawurlencode($identifier);
    }

    public function resolve(string $location, string $path): ResourceReference
    {
        if (str_starts_with($location, $this->baseUri)) {
            $encodedIdentifier = substr($location, strlen($this->baseUri));
            $identifier = rawurldecode($encodedIdentifier);

            if ($this->isSingleSegment($identifier)
                && $this->fromSkillIdentifier($identifier) === $location) {
                return new ResourceReference($identifier, $this->normalizePath($path));
            }
        }

        throw new RuntimeException(sprintf('Skill location "%s" is not available.', $location));
    }

    private function normalizePath(string $path): string
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
