---
status: accepted
---

# Select skills by declared name and prepare scripts separately

Skill instructions refer to other skills by declared name. The toolkit exposes
`skill(name)` to load the complete Markdown instructions without YAML frontmatter,
`skill_resource(name, path)` to read one supporting file, and
`skill_script_prepare(name)` to prepare all files of a skill for a separate
execution tool. Frontmatter configures discovery and invocation; activation
returns the instructions to follow rather than those configuration fields.

The agent-facing skill catalog contains one name and description per selected
skill. The repository selects the first valid document in configured storage
order, sorting identifiers within each storage. Its internal catalog retains
all valid skills, including same-name entries, with their owning storage and
identifier. All three tools use the same selection; a missing resource fails
without falling back to another same-name skill.

Script preparation enumerates `Resource` objects from the selected skill,
including `SKILL.md`, scripts, configuration, references and binary assets. This keeps dependency selection
out of the agent's preparation call and preserves relative paths and bytes.
For a storage implementing `LocalSkillStorageInterface`, preparation returns
its existing absolute native directory. For other storages, it reads the full
inventory through each resource’s `load()` and copies it into a fresh temporary
directory. The result contains `directory` and relative `files`.

Preparation returns an error when the local directory is invalid or a required
read or write fails; it never reports an incomplete directory as successfully
prepared. Execution belongs to a separate tool. Cleanup of temporary directories,
including partial copies after errors, belongs to the application or environment.
Ordinary instruction and resource reads do not prepare files.

The reading procedure is defined in
[ADR-0002](0002-single-resource-reading.md). Storage identity and capabilities
are defined in [ADR-0003](0003-storage-owned-skill-names.md).
