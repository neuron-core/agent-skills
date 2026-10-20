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

    protected ?string $description = 'Read one supporting file named in loaded instructions or references. '
        .'Returns its complete text; follow further required paths from that text in order.';

    public function __construct(protected SkillRepository $repository)
    {
    }

    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'The declared name of the skill that owns this file.',
                required: true,
                enum: array_values(array_unique($this->repository->names())),
            ),
            new ToolProperty(
                name: 'path',
                type: PropertyType::STRING,
                description: 'Literal path copied from already-read instructions or the latest reference contents, '
                    .'relative to the owning skill root. Preserve spaces, percent signs and Unicode.',
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
