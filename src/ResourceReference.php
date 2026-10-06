<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills;

final class ResourceReference
{
    public function __construct(
        public readonly string $skillIdentifier,
        public readonly string $path,
    ) {
    }
}
