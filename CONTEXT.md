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

**Skill resource**:
A supporting file belonging to a skill, such as a reference, script or asset.

**Skill catalog**:
The names, descriptions and locations of skills available for an agent to
discover before loading their instructions.

**Skill storage**:
A source containing skills and their supporting resources.

**Mount point**:
The complete public root address of a skill storage, including its scheme and
root path or logical name, such as `file:///app/skills/` or `db://team/`.
_Avoid_: Scheme alone when referring to a mount point.

**Skill location**:
The complete URI of an individual skill within a skill storage, such as
`file:///app/skills/caveman/`, excluding the document or resource path.

**Resource path**:
The path of a file relative to the skill root, such as `SKILL.md` or
`references/guide.md`.
