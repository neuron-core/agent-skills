---
status: accepted
---

# Use literal relative paths for skill resources

Keep exact skill-root URIs as selectors, but accept literal relative paths for
resources. A caller copies the skill location from the catalog and passes the
resource path as written, including spaces and percent signs. For example,
`my guide.md` selects that filename and `my%20guide.md` selects a filename with
the literal characters `%20`.

Storage adapters validate and normalize `.` and `..` in the literal path,
rejecting paths that leave the skill root. Filesystem storage then
resolves symlinks with `realpath()` and checks that the selected file remains
inside the real skill directory. Lexical normalization precedes symlink
resolution, so `docs/../guide.md` selects `guide.md` at the skill root even if
`docs` is a symlink.

This supersedes [ADR-0003](0003-relative-resource-uris.md). It removes the
requirement for callers and models to URI-encode resource filenames. Skill
locations and file URI mounts remain URI encoded. The storage `read` interface
accepts the location and literal path as separate arguments.
