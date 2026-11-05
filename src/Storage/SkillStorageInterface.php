<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return the unique, non-empty skill identifiers in this storage.
     *
     * @return list<string>
     */
    public function list(): array;

    /**
     * Return the literal resource paths of a skill, relative to its skill root and including SKILL.md.
     * Paths are strings to pass to read(); Skill::resources() wraps them as Resource objects.
     *
     * @return list<string>
     * @throws RuntimeException
     */
    public function resources(string $skillIdentifier): array;

    /**
     * Read file contents as a PHP string using a storage-specific skill identifier and a literal relative path.
     * Throw RuntimeException for expected failures such as an unknown resource or unreadable file.
     *
     * @throws RuntimeException
     */
    public function read(string $skillIdentifier, string $path): string;
}
