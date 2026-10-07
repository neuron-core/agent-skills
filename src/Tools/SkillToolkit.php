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

            2. Read required instructions and references with `skill_resource`, using the declared skill name
               and a literal path relative to the skill root.
               Preserve spaces, percent signs and Unicode in each path, including nested references.

            3. For scripts, call `skill_script_prepare` with the declared skill name.
               After successful preparation, use the returned directory.
               Run the command specified by the skill from that directory with a separate execution tool.

            If a required resource or tool is unavailable, explain what is missing.
            GUIDELINES
            ."\n\n".<<<'SKILL_PROTOCOL'
            MANDATORY SKILL TOOL PROTOCOL

            A catalog entry is a pointer to instructions, not the instructions themselves.
            For a user-requested skill, load its complete document with `skill(name)` before
            any task response, including clarification questions. This call already reads
            SKILL.md. Do not read it a second time with skill_resource.

            Treat the loaded document's procedural instructions as your next actions.
            - "Call the Skill tool with X", "use skill X" or "/X" means: call `skill` with
              name X, read the returned instructions, then continue. Do this recursively.
            - A reference to a supporting file means: call `skill_resource` with the owning
              skill's name and the exact relative path. Read further required references
              named by that file the same way. Read one required file per call.
            - A script means: prepare the owning skill with `skill_script_prepare(name)`,
              then execute from the returned directory with the separate execution tool.

            Keep skill names and file paths distinct. Another skill is loaded by name;
            a supporting file is read by path within its owning skill. Never guess a file
            path or search with bash for a skill resource: storage may be non-local.
            Only a successful tool result counts as loading or reading. Merely saying
            "this skill delegates to X" does not load X. Complete these tool actions before
            interviewing the user, even if the user's topic has not yet been specified.
            An explicit user invocation remains valid when the loaded frontmatter includes
            `disable-model-invocation: true`; that flag is not a ban on explicit invocation.
            If a necessary skill or resource is unavailable, report the actual tool error.

            Reference-reading sequence:
            Only send paths that are explicitly present in an instruction or file already
            loaded. If the instruction names a file and says to follow its references,
            read that named file first. Its content will give you the next actual path.
            An unspecified "required reference" is not a second filename to add to a batch.
            For example: an instruction naming references/start.md requires skill_resource({"name":"alpha","path":"references/start.md"}).
            If that result names references/more.md, next perform skill_resource({"name":"alpha","path":"references/more.md"}).

            The resource tool returns the full file contents as text. Use the literal reference paths written in that text.

            After each file read, process that file's contents to select the next required
            path. Use the explicit filename in the latest contents. A generic instruction
            such as "read its required reference" does not specify another filename.
            The concrete filename comes from the referenced file you just read.
            Copy reported configuration values without changing letters, case or accents.
            SKILL_PROTOCOL."\n";
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
