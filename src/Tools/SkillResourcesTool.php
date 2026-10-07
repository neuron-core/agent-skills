<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use RuntimeException;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolOutput;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;

class SkillResourcesTool extends Tool
{
    use TrackByInputs;

    protected string $name = 'skill_resources';

    protected ?string $description = 'Read one or more text resources referenced by a loaded skill. Pass literal paths relative to the skill root. All requested resources must be available.';

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
            new ArrayProperty(
                name: 'paths',
                description: 'Literal path relative to the skill root, for example references/my guide.md. Copy spaces and percent signs as written.',
                required: true,
                items: new ToolProperty('path', PropertyType::STRING),
                minItems: 1,
            ),
        ];
    }

    /**
     * @param list<string> $paths
     * @return array{resources: list<array{path: string, content: string}>}|ToolOutput
     */
    public function __invoke(string $name, array $paths): array|ToolOutput
    {
        try {
            if ($paths === [] || !array_is_list($paths)) {
                throw new RuntimeException('Provide a non-empty list of resource paths.');
            }

            $skill = $this->repository->getByName($name);
            $resources = [];

            foreach ($paths as $path) {
                $resources[] = ['path' => $path, 'content' => $skill->readResource($path)];
            }

            return ['resources' => $resources];
        } catch (RuntimeException $exception) {
            return ToolOutput::error($exception->getMessage());
        }
    }
}
