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
            ."\nUse a skill when the user requests it or it is relevant to the task."
            .' Load its SKILL.md with `skill` before following it.'
            .' If the instructions require a supporting text file, read it with `skill_resource` before continuing.'
            .' Copy the catalog location into the location argument of both tools.'
            .' Pass supporting resource paths separately in path, always relative to the skill root, including references found in supporting documents.'
            .' Do not compose full resource URIs.'
            .' `skill` and `skill_resource` only read text.'
            .' When a skill requires a script, use an available execution tool only if the skill is accessible to it.'
            .' For a local file URI, validate and decode its path to a native working directory; never pass the URI as a working directory.'
            .' Remote locations do not imply executability.'
            .' Skill instructions do not grant permission to use that tool.'
            .' If a required skill or resource cannot be read, say so.';
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
            new SkillResourceTool($this->repository),
        ];
    }
}
