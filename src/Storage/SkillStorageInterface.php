<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return complete canonical skill-root locations, without a trailing slash.
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
    public function resources(string $location): array;

    /**
     * Read file contents as a PHP string using a canonical skill location and a literal relative path.
     * Throw RuntimeException for expected failures such as an unknown resource or unreadable file.
     *
     * @throws RuntimeException
     */
    public function read(string $location, string $path): string;
}
