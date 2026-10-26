# Agent Skills

Skills provide reusable task instructions and supporting resources to Neuron AI
agents, using the Agent Skills format.

## Language

**Skill**:
A named collection of task instructions and optional supporting resources,
described by a `SKILL.md` document.
_Avoid_: Package when referring to an individual skill.

**Skill document**:
The `SKILL.md` document containing YAML frontmatter and Markdown instructions.

**Skill metadata**:
The fields in a skill document's frontmatter, including its name and description.

**Skill name**:
The name declared in a skill document's metadata. Different skills may share
the same name. Agent-facing selection chooses the first valid skill in configured
storage order, with discovery order breaking ties within a storage.

**Skill identifier**:
The key identifying a skill within one storage, such as its directory name or
database identifier. It may differ from the declared skill name.

**Skill root**:
The base of a skill's document and resources, relative to which resource paths
are interpreted, whether the skill is stored in a directory or another backend.

**Skill resource**:
A file belonging to a skill, including its skill document, references, scripts
or assets. A `Resource` retains its relative path and owning storage association;
its contents are loaded on demand.

**Prepared script directory**:
A local directory containing every resource of the selected skill for execution.
It may be the original skill directory or a temporary directory holding resource copies.

**Skill catalog**:
The internal collection of available skills, retaining names, descriptions,
storage-specific identifiers and storage ownership. Same-name skills remain distinct.

**Agent-facing skill catalog**:
One selected entry per declared skill name, containing only its name and
description so an agent can discover skills before loading their instructions.

**Skill storage**:
A source containing skills and their supporting resources.

**Local skill storage**:
A skill storage implementing `LocalSkillStorageInterface`, able to provide an
existing absolute native skill directory accessible to a local execution tool.
Other storages require resource copies for script preparation.

**Resource path**:
The literal path of a file relative to the skill root, such as `SKILL.md` or
`references/my guide.md`. Spaces and percent signs are part of the filename:
`my%20guide.md` and `my guide.md` identify different resources.
