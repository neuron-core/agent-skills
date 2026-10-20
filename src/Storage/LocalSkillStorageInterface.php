<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Storage;

use RuntimeException;

/** Storage whose skill files are available in their original local directory. */
interface LocalSkillStorageInterface extends SkillStorageInterface
{
    /**
     * Return the existing absolute native directory usable by a local execution tool.
     *
     * @throws RuntimeException
     */
    public function directory(string $skillIdentifier): string;
}
