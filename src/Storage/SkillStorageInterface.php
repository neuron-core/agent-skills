<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return skill identifiers scoped to this storage, without URI encoding.
     *
     * @return string[]
     */
    public function list(): array;

    /**
     * Return all literal relative file paths belonging to the selected skill, including SKILL.md.
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
