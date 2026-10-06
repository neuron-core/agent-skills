# Skill locations implementation

Status: resolved

## Notes

Implemented [the specification](spec.md) on `integration/skill-locations` for
[PR #10](https://github.com/neuron-core/agent-skills/pull/10), targeting `1.x`.
Ticket 01 unlocked tickets 02 and 03, which were implemented in parallel.

## Decisions-so-far

- [01: Exact location loading](issues/01-load-skills-by-location.md) — migrated
  storage, repository, tools, callers and documentation; implementation `bc57a0f`.
- [02: Distinct storage sources](issues/02-distinguish-storage-sources.md) —
  rejected duplicate locations and verified same-name source isolation and
  rollback; implementation `0842bfd`.
- [03: Filesystem locations and local execution](issues/03-filesystem-locations-and-local-execution.md)
  — verified encoded locations, symlink confinement and native execution;
  implementation `af5f786`. The example skill document is unchanged from the
  original baseline, following the user's instruction.
- Integrated validation: 145 PHPUnit tests, 475 assertions; PHPStan passed.
  Independent Standards and Spec reviews found no issues.

## Fog

None remaining within the specification's scope.
