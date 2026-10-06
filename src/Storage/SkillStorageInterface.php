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
     * Read a UTF-8 text file at a path relative to the selected skill root.
     * Throw RuntimeException for expected failures such as an unknown skill, invalid path, or unreadable file.
     *
     * @throws RuntimeException
     */
    public function read(string $location, string $path): string;
}
