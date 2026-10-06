<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

interface SkillStorageInterface
{
    /**
     * Return complete canonical skill-root locations, with a trailing slash.
     *
     * @return string[]
     */
    public function list(): array;

    /**
     * Read file contents as a PHP string using a canonical skill location and a literal relative path.
     * Throw RuntimeException for expected failures such as an unknown resource or unreadable file.
     *
     * @throws RuntimeException
     */
    public function read(string $location, string $path): string;
}
