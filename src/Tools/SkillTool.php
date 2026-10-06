<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill';

    protected ?string $description = 'Load an available skill\'s complete SKILL.md.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'location',
                type: PropertyType::STRING,
                description: 'The complete catalog location of the skill to load.',
                required: true,
                enum: array_map(static fn (Skill $skill): string => $skill->location(), $this->repository->catalog()),
            ),
        ];
    }

    public function __invoke(string $location): string
    {
        try {
            return $this->repository->get($location)->readDocument();
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
