<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use RuntimeException;

/** A skill resource bound to its owning storage and loaded on demand. */
final class Resource
{
    public function __construct(
        private readonly string $skillIdentifier,
        private readonly string $path,
        private readonly SkillStorageInterface $storage,
    ) {
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Read current bytes from the owning storage on every call, without caching.
     *
     * @throws RuntimeException
     */
    public function load(): string
    {
        return $this->storage->read($this->skillIdentifier, $this->path);
    }
}
