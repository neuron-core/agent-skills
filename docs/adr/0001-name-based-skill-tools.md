---
status: accepted
---

# Select skills by name and prepare script files separately

Generic skill instructions refer to other skills by name, so the implemented
tool interface selects declared names. The interface uses
`skill(name)` to return the complete skill document,
`skill_resource(name, path)` to read one resource, and
`skill_script_prepare(name)` to return a prepared script directory for the
complete skill.
The agent-facing catalog contains names and descriptions; location and storage
selection remain internal, with one selected source per name. The first
configured storage supplying a valid document wins, with deterministic discovery order breaking ties within
that storage. Internal repository access retains location-bearing skills and
storage ownership, including additional same-name skills.

Script preparation returns the original directory for filesystem skills and
creates temporary copies for database skills. Execution remains a separate
tool operation, and temporary-file lifecycle belongs to the application or
environment. This makes the agent's procedure independent of storage without
making ordinary skill loading write files.

Resource reads return the complete contents of one file as text. Missing or
invalid paths return an explicit error. The single-file reading contract and
operational guidelines are explained in [ADR-0002](0002-single-resource-reading.md).

Preparation includes every resource belonging to the selected skill, preserving
relative paths and bytes. This removes dependency selection from the agent.

## Storage interface

`list()` returns canonical skill locations and `read(location, path)`
returns resource bytes. `resources(location)` returns the skill's
relative file paths, including `SKILL.md`. These are the three storage methods.
Unknown skills and invalid locations produce errors.

Preparation recognizes the established `file://` location convention and
derives the native directory internally. For other locations it enumerates and
copies the skill resources to a temporary directory. This keeps the storage
interface smaller at the cost of retaining URI interpretation in preparation;
the agent does not interpret locations.

Filesystem enumeration respects the same resource confinement as reads;
database enumeration uses rows belonging to the selected skill. Storage
performs no preparation or execution. All three tools use the same selected
skill and owning storage. Preparation errors never return an apparently usable incomplete directory. Partial temporary
files remain an application responsibility; no cleanup lifecycle is introduced.
