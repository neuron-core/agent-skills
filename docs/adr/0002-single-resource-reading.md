---
status: accepted
---

# Read one skill resource at a time as text

A supporting file can name the next required reference, so the agent must read
its contents before choosing that next path. `skill_resource(name, path)` reads
one literal path relative to the selected skill root and returns the complete
file contents directly as text. The skill name and path are already in the call,
so the result needs no collection wrapper.

The toolkit's guidelines direct the agent to load skills with `skill(name)` when
their instructions are needed to perform the task. When loaded instructions name
another skill to call, the agent is directed to load it before performing steps
that depend on it. Required resource paths are copied from instructions or references
already read, preserving spaces, percent signs and Unicode.

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
