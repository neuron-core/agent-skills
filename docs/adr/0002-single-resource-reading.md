---
status: accepted
---

# Read one skill resource at a time as text

A supporting file can name the next required reference, so the agent must read
its contents before choosing that next path. `skill_resource(name, path)` reads
one literal path relative to the selected skill root and returns the complete
file contents directly as text.

The toolkit's guidelines direct the agent to load requested or relevant skills
once, even when it already knows the answer, then follow their instructions
before responding.
Required references are read with `skill_resource`, copying their full relative
paths exactly. Paths are literal, so spaces, percent signs and Unicode are
preserved. Before executing a required script, the agent reads its references,
calls `skill_script_prepare`, then runs the script from the returned directory.

The selected skill provides a `Resource` whose `load()` delegates each read to
its owning storage and identifier.
The built-in storages validate literal relative paths, normalize confined dot
segments and reject paths escaping the skill root. Expected resource-read
failures return `ToolOutput::error()`; successful reads return the text itself,
including empty text.

This procedure makes each reference available before the next file is selected,
at the cost of one tool call per file. It is guidance for the model: the toolkit
does not parse prose to load dependencies automatically or enforce completion
before a response. Script preparation and execution remain separate operations
as defined in [ADR-0001](0001-name-based-skill-tools.md).
