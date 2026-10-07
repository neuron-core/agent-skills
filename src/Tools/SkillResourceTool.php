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

    protected ?string $description = 'Read one text resource referenced by a loaded skill. Pass a literal path relative to the skill root.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The declared name of the skill whose resources to read.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Literal path relative to the skill root. Copy spaces, percent signs and Unicode as written.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $name, string $path): string|ToolOutput
    {
        try {
            return $this->repository->getByName($name)->readResource($path);
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
