# 01: Load skill documents and resources from a database

**What to build:** An application supplies a PDO connection and a database mount, then discovers and loads database-backed skills through the existing repository and toolkit. The agent selects a catalog location to retrieve the complete skill document and reads supporting text with a separate relative path. Implement this complete flow with real SQLite tests, expected failures, essential usage documentation and a working CI configuration.

**Blocked by:** None (can start immediately).

**Status:** claimed

- [ ] Add DatabaseSkillStorage as a public implementation of the existing SkillStorageInterface. Keep FileSystemSkillStorage unchanged in name and preserve the two-method discovery/read contract; no new repository or toolkit interface is needed.
- [ ] The constructor takes the complete mount point first, an existing PDO connection second and an optional table argument defaulting to skills. Both the default table and a configured alternative work through the public API.
- [ ] Use the agreed table contract: skill_name, path and content, uniquely identified by the pair of skill_name and path. The application creates and populates the schema; the adapter only discovers and reads, without establishing connections, creating tables, migrating schema or writing resources.
- [ ] Validate the supported database mount structure and table configuration. Handle the table identifier safely and bind selection values through PDO parameters. Use portable SQL rather than SQLite-specific queries or a SQLite-only driver restriction.
- [ ] Discovery returns canonical complete skill-root locations with a trailing slash, excluding the document filename. Multiple resource rows for one skill produce one catalog candidate. The adapter owns location-to-identifier translation, and a backend identifier may differ from the document's declared name.
- [ ] Through SkillToolkit and SkillRepository, a selected database skill returns its complete original SKILL.md and the requested supporting text. Tool inputs continue to use location and a separate resource path, with catalog metadata and frontmatter parsing preserved.
- [ ] Resource paths stay relative to the selected skill root. Confined dot and parent segments work; invalid paths, absolute selectors and effective escapes are rejected. No name-based lookup, alternate-source retry or generic URI reader is added.
- [ ] Empty resource content is valid UTF-8 text. Unsupported binary content fails. Empty tables, malformed or unreadable skill documents and ordinary diagnostics retain existing behavior.
- [ ] Unknown locations, missing documents or resources, missing or inaccessible tables and other expected database access failures follow the existing exception/tool-result contract. Direct callers receive RuntimeException and model tools return readable expected failures; unexpected implementation errors still propagate. Missing tables are reported rather than created.
- [ ] Supporting content remains loaded on demand, and the repository retains its existing lazy catalog lifecycle. Do not add refresh mechanisms, session state or remote execution.
- [ ] Verify the end-to-end flow using real PDO and SQLite, primarily through SkillToolkit with the existing agent/provider testing style. Use direct repository assertions for its public PHP contract and direct storage tests for configuration and paths. The specification's test seams are already approved; do not mock PDO or test private resolvers, query strings or call counts.
- [ ] Document the public adapter, constructor configuration, application-owned table contract and runtime dependencies. Configure CI to provide PDO and its SQLite driver, and keep composer check passing. Broader source-identity cases belong to ticket 02; the standalone SQLite example belongs to ticket 03.
- [ ] Keep the work on a branch derived from integration/skill-locations. The eventual database PR must target integration/skill-locations, the branch of PR #10, rather than the default branch. Preserve the existing example skill documents and filesystem example.
