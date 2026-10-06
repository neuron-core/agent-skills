# Database skill storage

## Notes

Implemented on `integration/db-skill-storage` and delivered in
[PR #11](https://github.com/neuron-core/agent-skills/pull/11), based on
`integration/skill-locations` (PR #10). All three tickets are resolved.

## Decisions-so-far

- Use PDO for database access and SQLite for local testing, as requested.
- Implement a public storage adapter in the library, rather than an example-only
  adapter.
- Use DatabaseSkillStorage for the new adapter and keep FileSystemSkillStorage
  for the existing filesystem adapter. PDO is the database adapter's connection
  dependency, not its public naming prefix. This naming choice follows the
  user's decision after [reviewing Neuron AI conventions](naming-research.md).
- Target a generic PDO adapter across database drivers, rather than restricting
  the adapter to SQLite. Use portable SQL for discovery and reads; the initial
  automated verification uses SQLite.
- Store skill documents and resources in one table with `skill_identifier`, `path`
  and `content`, uniquely identified by `(skill_identifier, path)`. `SKILL.md` is a
  resource row in the same table.
- The application owns schema creation and population. The adapter receives an
  existing PDO connection and performs discovery and reads only.
- Make the table name configurable, defaulting to `skills`.
- The mount labels the entire selected table; it does not partition rows.
  Instances using the same connection and table with different mounts expose
  the same data at different public locations. Separate tables or databases
  provide data isolation.
- Add a separate local SQLite example. Its setup script creates and populates
  the table with a skill document and resources; existing example skill
  documents remain unchanged.
- Test with real PDO and SQLite connections. Verify the end-to-end flow through
  toolkit and repository public interfaces; test storage configuration and
  resource paths directly through the adapter's public interface.
- Compare skill identifiers and resource paths exactly, including case.
  `Guide.md` does not select `guide.md`; `Caveman` and `caveman` are distinct
  storage identifiers. Database comparison defaults must not cause a read to
  return a different identifier or path.
- Preserve the existing resource contract: paths are relative to the selected
  skill root, confined parent segments are allowed, absolute selectors and
  effective root escapes are rejected, and resources are returned as PHP strings.
- Preserve the existing repository lifecycle: catalog metadata is discovered
  lazily and cached; document and resource contents are read on demand. Declared
  skill names remain metadata rather than backend lookup keys.
- Preserve the bundled example skill document.

- [Ticket 01](issues/01-load-database-skills.md) resolved: public PDO storage,
  real SQLite toolkit tests, failure handling and CI support (`3856209`).

- [Ticket 02](issues/02-preserve-exact-source-identity.md) resolved: exact
  database identity, encoded locations, source isolation and lifecycle coverage
  (`ce202ec`), with review corrections in `f833594`.
- [Ticket 03](issues/03-add-sqlite-example.md) resolved: standalone SQLite setup
  and credential-free toolkit example, verified automatically (`4c51367`).
- Final verification: 202 tests / 640 assertions, clean PHPStan and valid
  Composer configuration. Standards and specification reviews have no
  outstanding findings after the corrections.

## Fog

None. The user confirmed the complete design before implementation.
