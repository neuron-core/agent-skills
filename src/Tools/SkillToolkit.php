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
            Load each requested or relevant skill once with `skill`, then follow the returned instructions.
            When instructions request another skill, call `skill` with that name next.
            Read required references with `skill_resource`.
            Before running a skill script, call `skill_script_prepare` after reading its required references.
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
