---
status: superseded by 0004-literal-resource-paths
---

# Use URI encoding for skill and resource references

Keep exact skill-root URIs as selectors and accept relative URI paths for
resources, so both public arguments use the same encoding convention.
Centralize skill address conversions in `Location` and file path conversions in
`Uri`, decoding references once before backend lookup; stored filenames and
database keys remain literal names. `ResourcePath` handles decoded paths, with
lexical normalization for database keys and filesystem resolution that preserves
symlink semantics.

This updates the resource argument described in [ADR-0001](0001-explicit-storage-mounts.md):
`my%20guide.md` selects `my guide.md`, and a literal `my%20guide.md` requires
`my%2520guide.md`. Resource enumeration and temporary execution directories are
separate work.
