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

class SkillResourceTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill_resource';

    protected ?string $description = 'Read a text file referenced by a loaded skill. When its instructions require a file, read it before continuing. Pass the path relative to the skill directory.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'location',
                type: PropertyType::STRING,
                description: 'The complete catalog location of the skill whose resource to read.',
                required: true,
                enum: array_map(static fn (Skill $skill): string => $skill->location(), $this->repository->catalog()),
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Path named in the skill instructions, for example references/checks.md.',
                required: true,
            ),
        ];
    }

    public function __invoke(string $location, string $path): string
    {
        try {
            return $this->repository->get($location)->readResource($path);
        } catch (RuntimeException $exception) {
            return $exception->getMessage();
        }
    }
}
