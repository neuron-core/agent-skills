# 03: Demonstrate database skill storage with a separate SQLite example

**What to build:** A developer can prepare a local SQLite database, populate a skill document and its supporting resources, and run a separate example that discovers and reads them through DatabaseSkillStorage and the public toolkit APIs. The example makes the boundary between application-owned setup and read-only storage clear and can be verified without a remote model or database service.

**Blocked by:** 01 — Load skill documents and resources from a database.

**Status:** claimed

- [ ] Add a database example separate from the existing filesystem example. Preserve the existing example skill documents and retain the filesystem example's behavior.
- [ ] Provide application-side SQLite setup that creates and populates the skills table with skill_name, path and content and uniqueness on the pair of skill_name and path. Store SKILL.md and supporting resources as rows of the same table.
- [ ] The example constructs its own PDO connection and passes it to DatabaseSkillStorage with a complete database mount. Schema creation and population remain outside the adapter; the adapter itself performs discovery and reads only.
- [ ] Running the example demonstrates a catalog location, loading the complete original skill document and reading a supporting text resource with that location and a separate relative path through the public APIs.
- [ ] Provide setup and run instructions, including PDO/SQLite requirements, the default skills table and its configurable alternative, application ownership of the schema and the meaning of the database mount.
- [ ] Explain that PDO is the adapter's connection dependency, SQLite is the local demonstration backend, and adapter queries target portable SQL. The SQLite setup does not become a general cross-database schema or migration framework.
- [ ] Database locations do not imply local script access or execution. The example does not materialize remote files, deliver binary resources or require changes to existing skill instructions.
- [ ] Verify setup and the demonstrated reads automatically with real PDO and SQLite and no live model, API credentials or remote database. Assertions concern observable example results and public storage/toolkit behavior, not SQL-string snapshots or private methods.
- [ ] composer check passes and the documentation matches the runnable example. This ticket can proceed independently of ticket 02 after ticket 01 is complete; it adds no extra dependency or final integration gate.
