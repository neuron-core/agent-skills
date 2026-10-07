<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill';

    protected ?string $description = 'Load a skill\'s complete task instructions. '
        .'Execute the instructions returned by this call before answering. '
        .'If the document instructs you to call another skill by name, '
        .'call this tool again with that other name before replying or asking questions.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The declared name of the skill to load.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
        ];
    }

    public function __invoke(string $name): string
    {
        try {
            return $this->repository->getByName($name)->readDocument();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
