# Read skill documents and resources through PDO storage

Status: resolved

The current local schema contract requires exact database comparisons for
`skill_identifier` and `path`. The former requirement below to tolerate
case-insensitive query comparisons through a PHP row filter is superseded.
The adapter returns fetched `content` directly; PDO settings that convert empty
strings to `null` are unsupported.

The resource argument's original native-path contract below is superseded by
[ADR-0003](../../docs/adr/0003-relative-resource-uris.md): public resource references
now use URI encoding, while database keys remain literal paths.

## Problem Statement

Applications can now select skills by exact location and combine several skill storages, but the library only supplies a filesystem adapter. Its database-shaped test fixture demonstrates the contract without accessing a real database. Application developers who already keep skill documents and resources in a database still have to write their own adapter.

Developers need a reusable PDO storage that works with their existing connection and a small, explicit table contract. They also need a local SQLite example and real database tests to demonstrate that location selection, source isolation and resource reading behave consistently with the existing toolkit.

## Solution

Provide a public DatabaseSkillStorage adapter implementing the existing discovery and text-reading contract. The application supplies a complete database mount point, an existing PDO connection and optionally a table name. The default table is skills.

The table contains skill_identifier, path and content, with each pair of skill_identifier and path identifying one resource. The skill document is stored as a SKILL.md row alongside supporting resources. The application owns schema creation and population; the adapter only discovers skills and reads their text.

Expose canonical skill-root locations derived from the mount and backend identifiers. The existing repository and tools select those locations exactly. A missing database resource never falls back to another storage. Identifiers and resource paths remain case-sensitive even when database comparison defaults are not.

Use PDO and portable SQL rather than limiting the adapter to SQLite. SQLite is the initial real database used for local demonstration and automated verification. Add a separate database example while preserving existing example skill documents.

## User Stories

1. As an application developer, I want a public PDO skill storage, so that I can use database-backed skills without writing a custom adapter.
2. As an application developer, I want to supply an existing PDO connection, so that connection configuration and credentials stay under my application's control.
3. As an application developer, I want to supply a complete database mount point, so that the storage has an explicit public address.
4. As an application developer, I want the adapter to validate its mount configuration, so that incompatible or malformed addresses fail clearly.
5. As an application developer, I want the default table to be named skills, so that the simplest configuration needs no table argument.
6. As an application developer, I want a configurable table name, so that I can integrate the adapter into an existing database layout.
7. As an application developer, I want to own schema creation and population, so that storage reads do not create or modify my database schema.
8. As an application developer, I want skill documents and supporting resources in one table, so that the storage model remains small and understandable.
9. As an application developer, I want each resource uniquely identified by skill_identifier and path, so that a read has an unambiguous result.
10. As a skill author, I want the declared skill name to remain metadata, so that it can differ from the database identifier.
11. As an application developer, I want the adapter to use portable SQL through PDO, so that its design does not depend on SQLite-specific queries.
12. As an application developer, I want to discover complete canonical skill locations, so that consumers can copy addresses instead of constructing them.
13. As an application developer, I want supported identifiers containing URI-sensitive characters to round-trip correctly, so that their public locations select the intended database rows.
14. As an agent, I want database skills to appear in the existing catalog with their names, descriptions and locations, so that discovery works consistently across sources.
15. As an agent, I want activation to return the complete original database skill document, so that its metadata and instructions remain available.
16. As an agent, I want to request supporting text with a location and a separate relative path, so that database-backed instructions use the same resource contract as local skills.
17. As an application developer, I want filesystem and database skills with the same declared name to coexist, so that I can intentionally expose both sources.
18. As an agent, I want every resource read to remain with the selected source, so that another same-name skill cannot supply unintended content.
19. As an application developer, I want missing resources and unknown locations to fail clearly, so that I can diagnose unavailable content.
20. As an application developer, I want expected failures reported as exceptions through direct PHP access and readable results through the tools, so that both callers can handle them appropriately.
21. As an application developer, I want exact identifier and path comparisons, so that changing capitalization cannot silently select another skill or resource.
22. As a skill author, I want relative paths that remain inside the skill to work, so that existing supporting-file references remain usable.
23. As an application developer, I want absolute selectors and effective parent escapes rejected, so that resource paths cannot switch skills or sources.
24. As an application developer, I want resource content returned as stored, including empty strings and binary bytes, so that the adapter does not silently alter it.
25. As an application developer, I want catalog metadata to retain the existing discovery lifecycle while content is read on demand, so that adding database storage does not introduce eager resource loading or an implicit refresh policy.
26. As an application developer, I want two mounts over the same connection and table to expose the same data under different addresses, so that the mount's role is explicit rather than an implicit row filter.
27. As an application developer, I want data isolation to come from selecting separate tables or databases, so that I do not mistake a public mount label for a tenancy boundary.
28. As an application developer, I want empty tables and malformed skill documents handled consistently with other storages, so that ordinary catalog diagnostics remain useful.
29. As a maintainer, I want tests using real PDO and SQLite connections, so that SQL execution and database comparison behavior are verified rather than simulated.
30. As a developer trying the feature, I want a separate local SQLite setup and example, so that I can prepare sample data and exercise the storage without provisioning a remote database.
31. As a maintainer, I want the existing example skill documents preserved, so that adding database storage does not rewrite their instructions.
32. As a reviewer, I want the new PR based on the skill-location integration branch, so that its diff contains the database work separately from the prerequisite location changes.

## Implementation Decisions

- Add DatabaseSkillStorage as a public library adapter implementing SkillStorageInterface. Keep the existing FileSystemSkillStorage name for the filesystem adapter. Preserve the two-method contract: list returns complete canonical skill-root locations, and read accepts a location plus a relative resource path and returns a PHP string. Do not introduce a new repository, toolkit or storage interface for database access.
- The constructor receives the complete mount point first, the existing PDO connection second, and an optional table argument defaulting to skills. The adapter does not establish connections, own credentials or provision a database.
- Use the database mount scheme already illustrated by the storage contract. Validate the supported mount structure and issue canonical skill-root locations with a trailing slash, excluding the document filename. The adapter owns translation between those locations and database identifiers; callers use catalog addresses verbatim.
- Use one table with skill_identifier, path and content, with a uniqueness constraint on the pair of skill_identifier and path. The skill document and supporting resources use the same row shape. Empty content is a valid value.
- The application owns the schema, the uniqueness constraint, population and updates. The adapter is read-only: discovery and reads must not perform schema creation, migrations or resource writes. A SQLite setup script in the example is application-side demonstration code, not adapter behavior.
- Keep the table name configurable. Handle the identifier safely rather than treating configuration as arbitrary executable SQL. Values used for skill and resource selection must be passed safely through PDO parameters.
- Target PDO databases generically, using portable SQL for discovery and reads without restricting the adapter to the SQLite driver. Initial automated verification is on SQLite; do not equate this with having verified every PDO driver. Application-managed schema creation avoids requiring a cross-database DDL layer.
- The mount labels all data in the selected table. It does not select a tenant, filter rows or add an implicit namespace column. Two adapters over the same connection and table with different mounts expose the same rows at different public locations. Select separate tables or databases when data isolation is required.
- skill_identifier is the backend identifier used to address a skill, not an enforced copy of the declared name in the skill document. Preserve support for different backend identifiers and declared names, as well as same-name skills at distinct locations.
- Compare skill identifiers and resource paths exactly, including case. Guide.md must not read guide.md, and Caveman must not select caveman. Database collation defaults must not cause discovery to merge distinct identifiers or a read to return a different identifier or path. Document the schema's responsibilities for preserving the agreed identity and uniqueness rules.
- Encode identifiers safely when generating locations and round-trip supported URI-sensitive names. Resource paths remain separate native text inputs; do not URI-decode them repeatedly or interpret them as full addresses.
- Preserve resource confinement and relative-path behavior. Normalize relative dot and parent segments that remain inside the selected skill; reject invalid paths, absolute selectors and effective escapes from that skill's root. Supporting-file references remain relative to the skill root, including references encountered in other resources.
- Preserve the existing exact-location repository routing, duplicate-location rejection and absence of source fallback. The new adapter must work with existing storage registration and public tools without name-based selection or prefix routing.
- Preserve the existing catalog lifecycle: metadata is discovered lazily and retained by the repository, while skill documents and requested resources are read on demand. Do not eagerly load every resource's content, add a catalog refresh mechanism or introduce session state.
- Preserve original document content, frontmatter parsing and ordinary diagnostics for unusable documents. Expected unavailable-skill, missing-resource, invalid-path, unreadable-content and database access failures must be intelligible through the existing exception and tool-result contracts. Unexpected implementation errors must not become successful text responses.
- Return fetched content directly, including empty strings and binary bytes. A database location does not imply executable or locally materialized scripts.
- Add a separate local SQLite example with setup that creates and populates the agreed table, then demonstrates discovery and reading through the public storage/toolkit APIs. Keep the existing example skill documents unchanged and retain the filesystem example.
- Update public usage and dependency guidance for the PDO adapter, table contract, mount semantics and local SQLite example. Configure automated tests to have PDO and its SQLite driver available.
- Keep the database work on a separate branch derived from integration/skill-locations. The new PR must target integration/skill-locations, the branch of PR #10, rather than the repository's default branch.

## Testing Decisions

- Test externally visible discovery, selected locations, original returned text and observable failures. Avoid private resolver tests, implementation call counts, SQL-string snapshots or mock PDO connections.
- Use real PDO connections with SQLite for the initial automated suite. The user explicitly approved this backend and the public test seams during the grilling; no additional seam approval is outstanding.
- The primary seam is SkillToolkit, exercising catalog guidelines and public tools through the existing agent/provider testing style. SkillRepository provides the direct PHP seam for exact selection, source isolation, expected exceptions and existing lifecycle behavior. Use the adapter's public interface directly for configuration and resource-path cases.
- Reuse the approach of SkillToolkitTest and MultipleSkillStoragesTest, including FakeAIProvider for model interaction. Use SkillRepositoryTest as prior art for direct PHP expectations and FileSystemSkillStorageTest for storage configuration and path behavior. The existing TableSkillStorage fixture supplies the agreed table shape, but real SQLite replaces simulated database access in the new adapter's tests.
- Verify the default skills table and a configured alternative table through public behavior. Verify that the adapter uses the supplied connection, rejects malformed configuration and reports missing or inaccessible tables without creating them.
- Verify canonical discovery and document/resource reads, including a backend identifier that differs from the declared skill name and supported identifiers requiring URI encoding. Repeated resource rows for one skill must result in one discovered skill location.
- Exercise filesystem and PDO skills with the same declared name through the public tools. Select each independently and verify its original document and resource content. When a resource exists only in the other source, the selected PDO read must fail rather than fall back.
- Verify exact identifier and resource-path comparisons with case-sensitive and case-insensitive SQLite comparison configurations. Cover a requested spelling that differs only by case, as well as distinct identifiers that discovery must not collapse.
- Verify that two mounts over the same connection and table expose the same content under distinct addresses, and that choosing a different table isolates data. Preserve the repository's rejection of duplicate discovered locations.
- Cover valid relative dot/parent segments, root escapes, absolute selectors, invalid paths and literal percent characters in resource names. Assert selected-source behavior rather than internal normalization steps.
- Cover empty tables, empty resource content, binary resource content, unknown locations, missing documents or resources, malformed skill documents and database failures. Preserve expected direct exceptions, readable tool errors and propagation of unexpected implementation errors.
- Verify that resource content is read on demand and that the existing catalog metadata lifecycle remains unchanged when database rows are updated or removed after discovery. Do not introduce a broader refresh or concurrency feature through tests.
- Exercise the SQLite example without requiring a live model, API credentials or a remote database. Verify that setup is outside the adapter and that the example demonstrates the approved table and public API.
- Run the repository's composer check command for PHPUnit and static analysis after implementation. Ensure CI explicitly provides the PDO/SQLite runtime needed by the tests. Publishing this specification alone does not require running executable implementation checks.

## Out of Scope

- Adapter-owned schema creation, migrations, resource writes, connection provisioning or credential management.
- An ORM, general query builder, cross-database DDL framework or new database abstraction interface.
- Tenant columns, row-level namespaces, authorization filtering or treating mounts as data isolation boundaries.
- Remote script execution, filesystem materialization of database resources or binary delivery through the skill tools.
- Changes to the existing skill-location contract, name-based selection, prefix routing, fallback between sources or a new catalog refresh/session mechanism.
- A guarantee that every PDO driver has been tested, or a requirement to provision and test every supported database engine in this initial iteration.
- Editing existing example skill documents or replacing the filesystem example.
- Release publication or merging the prerequisite PR as part of this feature.

## Further Notes

The user confirmed the complete design after the grilling. The default table name was explicitly changed from the initially suggested skill_resources to skills.

This feature extends the exact-location implementation and the architectural decision behind it. The earlier decision described database storage as a possible future adapter; this specification supplies that next feature without changing its routing or resource-addressing model.

The new PR must be stacked on PR #10, with integration/skill-locations as its actual base. A feature/pdo-skill-storage branch has already been prepared, but no database adapter implementation or new PR has been created. Publishing this specification does not authorize starting implementation in this turn.

PDO and its SQLite driver have been installed and verified in the local system PHP. Use that runtime for future implementation checks; no temporary extension-loading setup is needed.

## Answer

Implemented by tickets [01](issues/01-load-database-skills.md),
[02](issues/02-preserve-exact-source-identity.md) and
[03](issues/03-add-sqlite-example.md) on `integration/db-skill-storage`.
[PR #11](https://github.com/neuron-core/agent-skills/pull/11) targets
`integration/skill-locations`, the branch of PR #10.

Final `composer check`: 202 tests, 640 assertions and no PHPStan errors.
Composer validates strictly. Standards and spec review findings were resolved
in `751360a`; original filesystem example skill documents remain unchanged.
