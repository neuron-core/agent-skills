---
status: accepted
---

# Keep skill identifiers scoped to their owning storage

A skill's declared name is metadata used for selection; its identifier is a key
within one storage. The repository retains both together with the owning storage
so names can differ from directory names or database keys and equal identifiers
can coexist across storage registrations.

`SkillStorageInterface` separates discovery and file access:

- `list()` returns unique, non-empty identifiers scoped to the storage, without
  URI encoding.
- `resources(skillIdentifier)` returns every literal relative file path belonging
  to that skill, including `SKILL.md`.
- `read(skillIdentifier, path)` returns the resource bytes as a PHP string and
  throws `RuntimeException` for expected access failures.

The repository reads `SKILL.md` for each discovered identifier and parses its
name and description to build the skill catalog. Each `Skill` retains that
association and exposes `identifier()` and `storage()`.
`getByName(name)` returns the first valid match; `findByName(name)` returns all
matches. `Skill::resource(path)` returns a `Resource` retaining the owning storage,
skill identifier and literal relative path; `Skill::resources()` returns the
inventory as a list of `Resource` objects. The storage interface continues to
return paths from its own `resources()` method.

Creating a `Resource` performs no read or existence check. Its `path()` returns
the supplied path and `load()` reads current bytes through the retained storage,
without caching or deriving an identifier from the declared name.
Skill document reads use the same resource loading and keep document parsing
in `Skill`. This lets callers enumerate and pass resources without carrying a separate
skill association for subsequent reads.

`StoragePaths` supplies independent `validateIdentifier(identifier)` and
`normalizePath(path)` operations for the built-in storages. It has no storage
dependency and performs no I/O. Validation returns no intermediate object;
normalization returns the relative path string. Built-in storages validate and
normalize during access, including calls made directly to the storage.

Discovery is lazy and the repository retains its catalog after access. Each
storage's identifiers are sorted before documents are read. Repeated identifiers
within one `list()` result fail discovery before document reads, without
publishing a partial catalog for that storage. Equal identifiers across
registrations, including repeated registration of the same storage, are allowed.
Unreadable or invalid documents are skipped; diagnostics retain `storage`,
`skillIdentifier` and `message` so their source remains attributable. Documents
and resources are read on demand through the retained storage association.

`LocalSkillStorageInterface` adds
`directory(string $skillIdentifier): string`, returning an existing absolute
native directory usable by a local execution tool. `FileSystemSkillStorage`
implements this capability. Script preparation uses the capability to choose
between the original directory and temporary copies, as defined in
[ADR-0001](0001-name-based-skill-tools.md).

Storages own discovery, enumeration and reads; the repository owns document
parsing and name selection; the preparation tool owns resource copies. Local
availability is an explicit capability, so the preparation tool needs neither
backend-specific routing nor a separate remote-storage interface. Filesystem
access confines resources to the canonical skill directory; database access
uses the owning PDO connection, table and identifier. The database schema,
updates and exact identifier/path comparisons belong to the application.
