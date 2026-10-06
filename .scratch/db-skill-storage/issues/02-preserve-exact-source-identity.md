# 02: Preserve exact database identity and isolate skill sources

**What to build:** Applications can select the intended database skill and resource even when database comparisons ignore capitalization, and agents can independently use same-name filesystem and database skills. Verify the full public flow for exact identity, encoded locations, table and mount boundaries, and content changes after discovery, fixing the adapter wherever those scenarios reveal gaps.

**Blocked by:** 01 — Load skill documents and resources from a database.

**Status:** claimed

- [ ] Identifiers and resource paths are compared exactly, including case. Guide.md does not read guide.md, and Caveman does not select caveman. Database comparison defaults must not cause discovery to merge distinct identifiers or reads to return a different identifier or path.
- [ ] Verify exact selection using real SQLite configurations with both case-sensitive and case-insensitive comparisons. Test both incorrectly capitalized requests and distinct identifiers that must remain independently discoverable. Keep the application-owned uniqueness constraint and document the schema's responsibilities for preserving identity.
- [ ] Supported identifiers containing URI-sensitive characters round-trip through canonical discovery, catalog output, exact selection and document/resource reads. Include spaces, literal percent characters and non-ASCII identifiers. Resource paths remain separate text inputs; literal percent characters are not repeatedly URI-decoded.
- [ ] Exercise a filesystem skill and a real PDO-backed skill with the same declared name through the public toolkit and repository. Both appear in the catalog and each returns its own original document and resources. Include a database identifier that differs from the declared name.
- [ ] When a resource is absent from the selected database skill but present in the same-name filesystem skill, the tool reports the database failure and direct PHP access throws RuntimeException. No fallback occurs.
- [ ] Two adapters over the same connection and table with different mounts expose the same data under distinct locations. Mounts do not filter rows or imply tenancy. Selecting a different table isolates its content; the adapter does not gain an implicit namespace column.
- [ ] Preserve the repository's duplicate-location failure when database storage registrations claim the same public locations. Verify through public access rather than inspecting ownership maps.
- [ ] Database updates and removals after catalog discovery are reflected by on-demand document/resource reads, including expected failures for deleted content, while existing catalog metadata retains its established lifecycle. Do not introduce a refresh API or a broader concurrency feature.
- [ ] Reuse SkillToolkit, SkillRepository and the existing multi-storage agent/provider test approach. PDO and SQLite connections are real; assertions concern catalog entries, selected text and observable failures. Use direct adapter tests only for the approved configuration and resource-path seam.
- [ ] Update usage guidance for exact capitalization, encoded locations, same-name coexistence, source confinement and the distinction between public mount labels and database/table isolation. Keep portable SQL and accurately state that initial automated database verification is on SQLite.
- [ ] composer check passes. Preserve FileSystemSkillStorage and existing example skill documents; do not add database provisioning, resource writes, remote execution or name-based selection.
