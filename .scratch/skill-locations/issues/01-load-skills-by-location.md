# 01: Load skills and resources by exact skill location

**What to build:** An application registers filesystem skill storage using a complete mount point, and its agent discovers skill-root locations in the guidelines. The agent copies a location to load the skill document, then reads supporting text using the same location and a separate relative resource path. Direct PHP consumers can perform the same selection and reads. Implement the complete vertical path and migrate affected callers and tests together so the project remains usable with the new contract.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] SkillStorageInterface exposes only list and read. Discovery returns complete canonical skill-root locations; reading accepts a location and a relative resource path and returns UTF-8 text. The previous adapter location method is removed, and no mountPoint method is introduced.
- [ ] FileSystemSkillStorage accepts a complete local absolute file URI mount as its first constructor argument, validates its scheme and structure, and translates it to a native filesystem root internally. Emitted skill locations use a consistent trailing slash and exclude the skill document filename.
- [ ] SkillRepository associates each discovered location with its owning adapter, loads metadata from the skill document and selects skills by exact location. Declared names remain metadata rather than lookup keys. No name-based fallback, prefix router or compatibility shim is introduced.
- [ ] Skill objects retain their discovered non-null location and selected owner. Existing document, instructions, frontmatter and relative resource access remain available through that owner.
- [ ] The skill tool accepts location; the skill_resource tool accepts location and path. Tool schemas and catalog-derived input choices use locations consistently.
- [ ] Guidelines expose each skill's name, description and complete root location. They instruct the model to copy the location and pass supporting resource paths separately, always relative to the skill root, without composing full resource URIs.
- [ ] Through the toolkit's public tools and direct PHP access, a discovered local skill returns its original complete document and the requested supporting text. Unknown locations and missing or unreadable files produce readable tool errors and RuntimeException for direct callers; unexpected implementation exceptions still propagate.
- [ ] Empty resource text remains valid. Existing text-only behavior, confinement of reads to the selected skill, empty-catalog behavior, metadata preservation, document diagnostics and lazy resource loading remain intact.
- [ ] Migrate existing storage doubles, affected tests, example configuration and public usage documentation to the new contract in this ticket. Preserve relevant regressions and deliberately replace assertions tied to name-based selection; do not leave the suite broken for later tickets.
- [ ] Verify the flow primarily through SkillToolkit and the existing agent/provider test setup. Add direct repository assertions only for its public PHP contract and reuse filesystem tests for basic mount validation and confinement. No real model or remote backend is required.
- [ ] The bundled example can load a skill and read its supporting text using the new contract. It does not present a file URI as an executable native working directory; the expanded local-execution and URI edge-case regression work is completed in ticket 03.
- [ ] The repository's composer check command passes. No production database/S3 adapter, remote script execution, general URI framework or session-handle mechanism is added.
