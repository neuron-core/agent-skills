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
            ."\nPass the declared name in `name` to `skill`. For duplicate names, the first configured storage wins."
            ."\nUse a skill when the user requests it or it is relevant to the task."
            .' Load its SKILL.md with `skill` before following it.'
            .' Read required instructions and references with `skill_resources`, using the declared skill name and a non-empty list of literal paths relative to the skill root, including references found in supporting documents.'
            .' Pass the literal path as written: references/my guide.md names that file, and percent signs remain literal.'
            .' Do not compose full resource URIs.'
            .' `skill` and `skill_resources` only read text.'
            .' When a skill requires a script, use an available execution tool only if the skill is accessible to it.'
            .' For a local file URI, validate its structure and decode its path once to a native working directory; never pass the URI as a working directory.'
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
            new SkillResourcesTool($this->repository),
        ];
    }
}
