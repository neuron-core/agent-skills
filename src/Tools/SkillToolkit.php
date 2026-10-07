<?php

declare(strict_types=1);

namespace NeuronAI\AgentSkills\Tools;

use NeuronAI\AgentSkills\Skill;
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\SkillStorageInterface;
use NeuronAI\Tools\Toolkits\AbstractToolkit;

use function array_map;
use function implode;
use function preg_replace;
use function trim;

class SkillToolkit extends AbstractToolkit
{
    protected SkillRepository $repository;

    public function __construct(?SkillRepository $repository = null)
    {
        $this->repository = $repository ?? new SkillRepository();
    }

    public function fromStorage(SkillStorageInterface ...$storages): static
    {
        $this->repository->addStorage(...$storages);

        return $this;
    }

    public function guidelines(): ?string
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return null;
        }

        return "Available skills:\n".$this->formatCatalog($catalog)
            ."\nPass the declared name in `name` to `skill`. For duplicate names, the first configured storage wins."."\n\n".<<<'GUIDELINES'
            1. Read the complete SKILL.md of each requested or relevant skill with `skill`
               before acting on its instructions.

            2. Read required instructions and references with `skill_resources`, using the declared skill name
               and a non-empty list of literal paths relative to the skill root.
               Preserve spaces, percent signs and Unicode in each path, including nested references.

            3. For scripts, call `skill_script_prepare` with the declared skill name.
               After successful preparation, use the returned directory.
               Run the command specified by the skill from that directory with a separate execution tool.

            If a required resource or tool is unavailable, explain what is missing.
            GUIDELINES;
    }

    /** @param list<Skill> $catalog */
    private function formatCatalog(array $catalog): string
    {
        $entries = array_map(static function (Skill $skill): string {
            $description = preg_replace('/\s+/u', ' ', $skill->description()) ?? $skill->description();
            $location = $skill->location();

            return '- '.$skill->name().': '.trim($description).' (location: '.$location.')';
        }, $catalog);

        return implode("\n", $entries);
    }

    public function provide(): array
    {
        $catalog = $this->repository->catalog();
        if ($catalog === []) {
            return [];
        }

        return [
            new SkillTool($this->repository),
            new SkillResourcesTool($this->repository),
            new SkillScriptPrepareTool($this->repository),
        ];
    }
}
