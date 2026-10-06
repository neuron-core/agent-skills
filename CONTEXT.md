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
the same name; the name alone does not identify the selected skill.

**Skill identifier**:
The key identifying a skill within one storage, such as its directory name or
database identifier. It may differ from the declared skill name.

**Skill root**:
The base of a skill's document and resources, relative to which resource paths
are interpreted, whether the skill is stored in a directory or another backend.

**Skill resource**:
A file belonging to a skill, including its skill document, references, scripts
or assets.

**Skill catalog**:
The names, descriptions and locations of skills available for an agent to
discover before loading their instructions.

**Skill storage**:
A source containing skills and their supporting resources.

**Base URI**:
The complete public root address of a skill storage, including its scheme and
root path or logical name, such as `file:///app/skills/` or `db://team/`.
_Avoid_: Scheme alone when referring to a storage's base URI.

**Skill location**:
The complete canonical URI identifying a skill root within a storage, such as
`file:///app/skills/caveman/`, with a trailing slash and no document or resource
path. It selects the skill independently of its declared name.

**Resource path**:
The literal path of a file relative to the skill root, such as `SKILL.md` or
`references/my guide.md`. Spaces and percent signs are part of the filename:
`my%20guide.md` and `my guide.md` identify different resources.
