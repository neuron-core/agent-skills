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

class SkillTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill';

    protected ?string $description = 'Load a skill\'s complete instructions by name. '
        .'Do not reload a successfully loaded skill; follow its returned instructions.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Name of the skill to load next.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
        ];
    }

    public function __invoke(string $name): string|ToolOutput
    {
        try {
            return $this->repository->getByName($name)->loadInstructions();
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
