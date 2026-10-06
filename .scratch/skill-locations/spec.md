# Address skills by exact location and read resources by relative path

Status: resolved

## Problem Statement

Applications can register several skill storages, but the catalog and tools currently identify skills by their declared names. When two sources contain the same name, the first usable skill wins and the other disappears from the catalog. The model cannot explicitly select either source, even though guidelines show a location.

The current location is an optional address accessible to host tools, while storage reads use a separate internal identifier. Developers and models need a consistent address for selecting a skill across local and custom storage, without requiring the model to construct full resource addresses or maintain session handles.

## Solution

Discover skills as complete, canonical skill locations. Associate each location with the storage that returned it and use that exact address for selection. Show name, description and location in the catalog, allowing skills with the same declared name to coexist at different locations.

The model loads instructions using the catalog location. To read supporting text it passes the same location and the resource path found in the instructions. Code resolves the path inside the selected skill. A location always identifies a skill root, never its document or a resource file.

The representative scenario contains a local skill and a database-backed test skill both named caveman. Selecting the database location loads that skill document and its guide. If its guide is absent, the read reports an error even when the local skill contains that guide. No source fallback occurs.

## User Stories

1. As an application developer, I want to register multiple storage adapters directly with the repository, so that I can combine skill sources without a separate mount wrapper.
2. As an application developer, I want to configure a storage with a complete mount point, so that its public address is explicit.
3. As an application developer, I want the adapter to reject an incompatible mount scheme or malformed configuration, so that configuration failures appear before model interaction.
4. As a storage author, I want discovery to return complete skill locations, so that callers do not have to construct them from backend identifiers.
5. As a storage author, I want to implement discovery and text reading through two methods, so that the storage contract stays small.
6. As a storage author, I want to own translation between public locations and backend identifiers, so that database keys and physical directories remain adapter concerns.
7. As an application developer, I want two skills with the same declared name at different locations to remain available, so that I can expose both sources intentionally.
8. As an application developer, I want duplicate skill locations to fail explicitly, so that registration order cannot silently change ownership.
9. As an application developer, I want routing by exact discovered location, so that overlapping storage roots do not require prefix precedence rules.
10. As an agent, I want each catalog entry to include its name, description and location, so that I can choose a relevant skill and address the intended source.
11. As an agent, I want to copy a location from the catalog into the activation tool, so that I do not have to infer an address.
12. As an agent, I want activation to return the original complete skill document, so that its metadata and instructions remain available.
13. As an agent, I want to pass a skill location and a resource path separately, so that I do not have to concatenate or encode a resource URI.
14. As a skill author, I want resource paths to remain relative to the skill root, so that the same document works across storage backends.
15. As an agent, I want every resource read to stay with the selected skill, so that an identically named skill elsewhere cannot supply unintended content.
16. As an agent, I want missing resources and unknown locations to produce understandable errors, so that I can explain why required material is unavailable.
17. As an application developer, I want direct PHP access to report expected failures through exceptions, so that my application can handle them.
18. As an application developer, I want resource reads confined to the selected skill, so that relative paths cannot access another skill or arbitrary files.
19. As a skill author, I want supported filesystem symlinks to keep working with confinement checks, so that installed skill layouts remain usable.
20. As an application developer, I want invalid or unreadable skill documents to remain diagnosable, so that an unrelated malformed skill does not silently damage the catalog.
21. As an application developer, I want metadata parsing and on-demand resource loading preserved, so that adopting locations does not eagerly load every supporting file.
22. As a storage author, I want declared names to remain metadata rather than routing keys, so that a document name need not equal a directory or backend identifier.
23. As an application developer, I want the examples and custom-storage guidance to use the new contract consistently, so that I can migrate existing integrations.
24. As an application developer, I want local script examples to distinguish file URIs from native working directories, so that the addressing change does not break existing local execution.
25. As a maintainer, I want the behavior verified through public toolkit and repository interfaces, so that tests survive internal refactoring.

## Implementation Decisions

- Modify SkillStorageInterface to expose two methods: list returns an array of complete skill-root location strings; read accepts a skill location and a separate resource path and returns UTF-8 text. Remove the adapter location method; do not add a mountPoint method.
- Keep storage adapters as the direct dependencies of SkillRepository and SkillToolkit storage registration. The adapter constructor receives the complete mount point as its first argument, with connection or client dependencies supplied separately when needed. Validate the mount using the adapter's supported scheme and address structure.
- The filesystem adapter accepts a file URI representing a local absolute storage root. Translate it to a native path internally. The mount remains adapter configuration rather than a public routing interface.
- Each adapter emits canonical skill-root locations and accepts those exact strings for reads. Use a consistent directory form with a trailing slash, excluding SKILL.md. Encode identifiers safely and round-trip supported directory names without decoding resource paths multiple times. The repository does not infer equivalence between arbitrary URI spellings; consumers use catalog addresses verbatim.
- Preserve filesystem containment against canonical native paths, including the existing distinction between a skill directory that is a symlink and a resource symlink escaping that skill. Public addresses and canonical filesystem targets must not be conflated.
- The repository indexes every discovered location with its owning adapter. A duplicate location is a configuration error, including duplicates returned by one adapter; it must not become first-wins shadowing or a skippable document warning. Conflicting discovery must not leave a partially usable ownership map.
- Do not route by mount prefixes or reject overlapping mount roots. This replaces the earlier proposal to enforce mount uniqueness and non-overlap. Uniqueness is enforced on discovered skill locations.
- Build catalog metadata by reading SKILL.md through the owning adapter. Preserve existing parsing, optional metadata, diagnostics and lazy catalog behavior except for the intentional identity change. Supporting resources remain loaded on demand.
- Keep distinct locations even when their skill documents declare the same name. Preserve the distinction between the declared name and the storage identifier; do not impose equality between them.
- SkillRepository get selects by exact skill location, not declared name. Catalog entries retain their owner and location. Skill location returns the discovered non-null address; document, instructions, frontmatter and resource access continue through the same selected source. Name listings, if retained, are descriptive rather than a selection interface.
- The skill tool accepts location and loads that skill's complete original SKILL.md. The skill_resource tool accepts location and path, with location meaning the same skill-root address in both tools. Update tool schemas, descriptions and any catalog-derived input choices accordingly.
- Guidelines include name, description and complete skill location. Instruct the model to copy the catalog location and pass supporting file paths separately. All relative resource references are rooted at the skill root, including references encountered in supporting documents. The model need not build full file URIs.
- Reads accept resource paths relative to the selected skill. Reject invalid paths and effective escapes. Relative parent segments need not be forbidden lexically if resolution stays within the skill, preserving the existing confinement semantics. Absolute addresses are not an alternative resource selector.
- A read never retries another adapter or an identically named skill. Empty text remains valid content. Preserve text-only behavior and existing rejection of unsupported binary content in the filesystem adapter.
- Expected unavailable-skill, missing-resource, invalid-path and unreadable-content failures use RuntimeException for direct PHP callers and readable error results for model tools. Unexpected implementation exceptions must not be converted into successful text responses. Preserve empty-catalog behavior.
- File URI locations identify storage resources; they do not guarantee direct access by an execution tool. Update local execution instructions and examples so native working directories are obtained from valid local file locations rather than passing file URIs as shell directory names. Remote locations do not imply executability.
- Breaking changes are permitted. Update the bundled example, documentation and affected tests to use complete filesystem mounts, location-based lookup and revised custom-storage methods. No dual name/location compatibility layer is required.
- For the database example or test fixture, use one table-shaped collection with skill_name, path and content, uniquely identified by the pair of skill_name and path. The adapter translates its skill location to skill_name. This illustrates the contract without requiring a production database adapter or a database migration.

## Testing Decisions

- Test externally visible selection, returned text, catalog entries and failures, rather than internal ownership maps, private resolver methods or implementation call counts. Reuse existing public interfaces instead of creating interfaces solely for tests.
- The primary test seam is SkillToolkit, exercising generated guidelines and registered tools through the existing agent/provider test setup. Reuse the style of SkillToolkitTest and MultipleSkillStoragesTest, with temporary filesystem skills and a custom in-memory storage representing the database contract. No live model, credentials or remote backend are required.
- Verify the representative scenario with two skill documents declaring the same name but distinct locations and different resource contents. Both entries must appear, activation must return the chosen document, and resource reading must return the chosen source's text.
- Verify the meaningful failure with a requested resource absent from the selected database fixture but present in the local skill. The tool must report the selected-source failure rather than return local content.
- Verify tool inputs use location consistently, guidelines expose a skill-root location without SKILL.md, and a supporting resource is retrieved using its separate relative path. Assert important behavior and instructions without snapshotting unrelated prompt wording.
- Cover unknown locations, missing documents or resources after discovery, empty resource content, unsupported binary files, empty catalogs and propagation of unexpected errors. Preserve relevant existing metadata, document fidelity and lazy-resource tests.
- Add direct SkillRepository tests only for the public PHP contract: lookup by location, distinct same-name entries, duplicate-location failure without partial ownership, and expected exceptions. Reuse SkillRepositoryTest and existing multi-storage regression scenarios.
- Reuse FileSystemSkillStorageTest for mount validation, file URI/native-path round trips, canonical emitted locations, names requiring URI encoding, invalid resource paths and actual filesystem confinement. Retain coverage for supported skill-directory symlinks, internal resource symlinks and links escaping the skill.
- Retain the local host-execution regression using a script and neighboring asset, updating it for the difference between a catalog file URI and a native execution path. This does not introduce remote execution support.
- Run the repository's composer check command after implementation to validate PHPUnit and static analysis. This specification itself adds no executable behavior and does not require running the implementation suite.
- The user explicitly confirmed this testing perimeter before publication of the specification.

## Out of Scope

- Production database or S3 adapters, migrations, connection provisioning and credentials.
- Execution or materialization of scripts stored in remote backends.
- Binary resource delivery through the skill tools.
- A generic URI reader that makes the model construct full resource addresses.
- Session handles, implicit active-skill state, mandatory opening state or handle lifecycle management.
- Automatic adapter loading from URI schemes, arbitrary URL fetching, prefix-based routing or mount-precedence rules.
- Name-based selection fallback and compatibility shims for the previous interfaces.
- Changing metadata enforcement policies, adding invocation restrictions or redesigning catalog refresh and caching.

## Further Notes

This specification implements the selected first alternative: exact skill location plus relative resource path. It follows the domain definitions in CONTEXT.md and the decision recorded in ADR-0001.

The competing alternatives were a single full-document-URI reader and opening a skill to obtain a session reference. The selected design keeps the model's task to copying known values while keeping resource resolution in code and avoiding additional session state.

Low-level URI normalization details remain implementation work within the stated invariants: adapters issue canonical addresses, callers copy them, repository lookup is exact, and backend reads remain confined. Document the supported filesystem URI form and cover its round-trip behavior in tests; do not introduce a general multi-scheme URI framework for this feature.

The specification is implemented on `integration/skill-locations`. Release publication is outside this work.

## Answer

Tickets 01–03 are resolved. Exact-location selection, source isolation and
duplicate rejection, encoded filesystem locations, confined reads and native
local execution are implemented and documented. The bundled example skill
document remains unchanged, as requested.

Final validation: `composer check` passes with 145 tests, 475 assertions and no
PHPStan errors. Separate Standards and Spec reviews of the implementation found
no issues. See [the implementation map](map.md) and
[PR #10](https://github.com/neuron-core/agent-skills/pull/10).
