# Exact skill locations and relative resource paths

Storage adapters will expose `list(): array` returning canonical, complete skill
root locations and `read(string $location, string $path): string` reading a text
file relative to that skill root. Neither `location()` nor `mountPoint()` is needed
on `SkillStorageInterface`. Each adapter receives its complete mount point in its
configuration and validates its scheme and structure; it owns location encoding,
backend access and confinement of resource reads to the selected skill.

`SkillRepository` will index each exact location with the adapter that listed it,
reject duplicate locations and discover metadata by reading `SKILL.md`. It will
not select adapters by mount-prefix matching or reject overlapping mount roots.
Names are metadata: skills with the same declared name at different locations
remain separately available, with no fallback between their resources.

Guidelines will show each skill's name, description and complete root location,
excluding `SKILL.md`. The `skill` tool accepts that location; `skill_resource`
accepts the same location and a separate path relative to the skill root. The
model copies the catalog location and the referenced path; code performs path
resolution. Expected read failures are reported to the model and propagated as
`RuntimeException` for direct PHP callers.

This deliberately favors two resource arguments over full resource URIs composed
by the model. It also avoids introducing session handles and mandatory opening
state solely to read supporting files. Both alternatives add caller obligations
without serving the current requirement better.

Breaking changes to existing interfaces are acceptable. This records the selected
design; implementation is pending. Remote script execution is outside this change.
Database and S3 examples describe possible adapters, not a commitment to implement
them as part of this decision. Detailed URI normalization rules remain to be
specified during implementation.
