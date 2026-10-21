---
name: rollback-plan
description: Plan how to recover from an unsuccessful deployment or migration. Use when a change needs rollback criteria, recovery steps, or post-rollback verification.
---

# Rollback plan

Describe how to restore service if the proposed change fails.

1. Identify the last known working state.
2. Define the symptoms that should trigger a rollback.
3. Describe the recovery steps and their prerequisites.
4. Explain how to verify that recovery succeeded.

Distinguish reversible changes from operations that require backups,
data repair, or a forward fix. State any missing information that prevents
a reliable recovery procedure.
