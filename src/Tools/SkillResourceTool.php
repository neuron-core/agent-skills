<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillResourceTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill_resource';

    protected ?string $description = 'Read a file that loaded instructions explicitly ask you to read. Copy its complete path exactly.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Skill name.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Full path relative to the skill root, including directories, e.g. references/guide.md.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $path): string|ToolOutput
    {
        try {
            return $this->repository->getByName($name)->resource($path)->load();
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
