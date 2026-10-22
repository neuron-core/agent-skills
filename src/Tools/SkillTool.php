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

    protected ?string $description = 'Load a skill\'s complete instructions.';

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
        ];
    }

    public function __invoke(string $name): string|ToolOutput
    {
        try {
            $instructions = $this->repository->getByName($name)->loadInstructions();

            return trim($instructions) === '' ? $instructions : "Instructions loaded.\n\n".$instructions;
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
