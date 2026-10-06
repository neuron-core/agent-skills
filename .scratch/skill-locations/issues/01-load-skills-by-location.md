# 01: Load skills and resources by exact skill location

**What to build:** An application registers filesystem skill storage using a complete mount point, and its agent discovers skill-root locations in the guidelines. The agent copies a location to load the skill document, then reads supporting text using the same location and a separate relative resource path. Direct PHP consumers can perform the same selection and reads. Implement the complete vertical path and migrate affected callers and tests together so the project remains usable with the new contract.

**Blocked by:** None (can start immediately).

**Status:** resolved

- [x] SkillStorageInterface exposes only list and read. Discovery returns complete canonical skill-root locations; reading accepts a location and a relative resource path and returns a PHP string. The previous adapter location method is removed, and no mountPoint method is introduced.
- [x] FileSystemSkillStorage accepts a complete local absolute file URI mount as its first constructor argument, validates its scheme and structure, and translates it to a native filesystem root internally. Emitted skill locations use a consistent trailing slash and exclude the skill document filename.
- [x] SkillRepository associates each discovered location with its owning adapter, loads metadata from the skill document and selects skills by exact location. Declared names remain metadata rather than lookup keys. No name-based fallback, prefix router or compatibility shim is introduced.
- [x] Skill objects retain their discovered non-null location and selected owner. Existing document, instructions, frontmatter and relative resource access remain available through that owner.
- [x] The skill tool accepts location; the skill_resource tool accepts location and path. Tool schemas and catalog-derived input choices use locations consistently.
- [x] Guidelines expose each skill's name, description and complete root location. They instruct the model to copy the location and pass supporting resource paths separately, always relative to the skill root, without composing full resource URIs.
- [x] Through the toolkit's public tools and direct PHP access, a discovered local skill returns its original complete document and the requested supporting text. Unknown locations and missing or unreadable files produce readable tool errors and RuntimeException for direct callers; unexpected implementation exceptions still propagate.
- [x] Empty and binary resource content is returned as a PHP string. Confinement of reads to the selected skill, empty-catalog behavior, metadata preservation, document diagnostics and lazy resource loading remain intact.
- [x] Migrate existing storage doubles, affected tests, example configuration and public usage documentation to the new contract in this ticket. Preserve relevant regressions and deliberately replace assertions tied to name-based selection; do not leave the suite broken for later tickets.
- [x] Verify the flow primarily through SkillToolkit and the existing agent/provider test setup. Add direct repository assertions only for its public PHP contract and reuse filesystem tests for basic mount validation and confinement. No real model or remote backend is required.
- [x] The bundled example can load a skill and read its supporting text using the new contract. It does not present a file URI as an executable native working directory; the expanded local-execution and URI edge-case regression work is completed in ticket 03.
- [x] The repository's composer check command passes. No production database/S3 adapter, remote script execution, general URI framework or session-handle mechanism is added.

## Answer

Implemented the complete location-based vertical flow across filesystem storage,
repository, Skill objects, toolkit schemas and guidelines. Storage exposes only
`list()` and `read($location, $path)`; exact discovered locations select owners,
while declared names remain metadata. File URI mounts are validated and emitted
as canonical skill-root addresses. Resource reads retain confinement and lazy
loading, and return content as a PHP string. Existing tests and storage doubles were migrated,
including same-name sources, diagnostics, original-document fidelity and host
execution using a decoded native path. Public usage docs and the bundled example
now use the new contract.

Validation: the toolkit tracer test first failed (empty catalog under the new
contract), then passed after implementation. Final `composer check` passes:
119 tests, 404 assertions, PHPStan with no errors. `git diff --check` and example
PHP syntax validation pass. A direct public-tool smoke check loaded the bundled
`php-check/SKILL.md` and `references/checks.md` byte-for-byte without a model.
Duplicate-location atomicity and the representative database fixture remain the
scope of ticket 02; expanded URI and local-execution cases remain ticket 03.
