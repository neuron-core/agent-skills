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

        return <<<'GUIDELINES'
            Use skills explicitly requested by the user or relevant to the task.
            Load their instructions with `skill` and follow them in order to completion.
            Load required skills before performing the steps that depend on them.
            Read required references with `skill_resource` before continuing.
            For scripts required by loaded instructions, execute from the directory returned by `skill_script_prepare`.
            GUIDELINES
            ."\n\nAvailable skills:\n".$this->formatCatalog($catalog);
    }

    /** @param list<Skill> $catalog */
    private function formatCatalog(array $catalog): string
    {
        $selected = [];
        foreach ($catalog as $skill) {
            $selected[$skill->name()] ??= $skill;
        }

        $entries = array_map(static function (Skill $skill): string {
            $description = preg_replace('/\s+/u', ' ', $skill->description()) ?? $skill->description();

            return '- '.$skill->name().': '.trim($description);
        }, $selected);

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
            new SkillResourceTool($this->repository),
            new SkillScriptPrepareTool($this->repository),
        ];
    }
}
