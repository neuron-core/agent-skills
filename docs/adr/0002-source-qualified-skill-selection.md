---
status: deprecated
---

# Select skills by source and storage identifier

Replace the URI-based selection proposed in [ADR-0001](0001-explicit-storage-mounts.md)
with separate source and skill identifiers, while resource reads accept literal
paths relative to the selected skill. This keeps same-name skills independently
selectable without requiring URI construction or percent encoding in the public
selection contract; the repository owns source registration, with explicit or
automatically assigned identifiers that do not add a database column or filter.

This proposal was withdrawn during the design discussion in favor of retaining
URI-based skill selection. Listing resources and preparing a temporary execution
directory can support database-backed scripts without replacing skill identity.
This proposal was never implemented. The later choice of literal resource paths
is recorded in [ADR-0004](0004-literal-resource-paths.md).
