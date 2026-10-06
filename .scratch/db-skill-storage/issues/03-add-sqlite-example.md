# 03: Demonstrate database skill storage with a separate SQLite example

**What to build:** A developer can prepare a local SQLite database, populate a skill document and its supporting resources, and run a separate example that discovers and reads them through DatabaseSkillStorage and the public toolkit APIs. The example makes the boundary between application-owned setup and read-only storage clear and can be verified without a remote model or database service.

**Blocked by:** 01 — Load skill documents and resources from a database.

**Status:** resolved

- [x] Add a database example separate from the existing filesystem example. Preserve the existing example skill documents and retain the filesystem example's behavior.
- [x] Provide application-side SQLite setup that creates and populates the skills table with skill_identifier, path and content and uniqueness on the pair of skill_identifier and path. Store SKILL.md and supporting resources as rows of the same table.
- [x] The example constructs its own PDO connection and passes it to DatabaseSkillStorage with a complete database mount. Schema creation and population remain outside the adapter; the adapter itself performs discovery and reads only.
- [x] Running the example demonstrates a catalog location, loading the complete original skill document and reading a supporting text resource with that location and a separate relative path through the public APIs.
- [x] Provide setup and run instructions, including PDO/SQLite requirements, the default skills table and its configurable alternative, application ownership of the schema and the meaning of the database mount.
- [x] Explain that PDO is the adapter's connection dependency, SQLite is the local demonstration backend, and adapter queries target portable SQL. The SQLite setup does not become a general cross-database schema or migration framework.
- [x] Database locations do not imply local script access or execution. The example does not materialize remote files, deliver binary resources or require changes to existing skill instructions.
- [x] Verify setup and the demonstrated reads automatically with real PDO and SQLite and no live model, API credentials or remote database. Assertions concern observable example results and public storage/toolkit behavior, not SQL-string snapshots or private methods.
- [x] composer check passes and the documentation matches the runnable example. This ticket can proceed independently of ticket 02 after ticket 01 is complete; it adds no extra dependency or final integration gate.

## Answer

Added application-owned SQLite setup and a separate runnable toolkit demonstration in `cc0bff6`, integrated by `4c51367`. Automated tests exercise setup and public reads without a model, credentials or a remote service; existing filesystem example documents remain unchanged.

Final integration validation: `composer check` passed (202 tests, 640 assertions), PHPStan reported no errors, and `composer validate --strict` passed. Delivered in [PR #11](https://github.com/neuron-core/agent-skills/pull/11), based on `integration/skill-locations` (PR #10).
