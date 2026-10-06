# 02: Distinguish same-name skills and reject duplicate skill locations

**What to build:** An agent can discover and independently select local and custom-storage skills with the same declared name. Every document and resource read stays with the chosen source. Applications receive an explicit configuration failure when discovery claims the same location more than once, instead of silently choosing an owner or retaining a partially updated catalog.

**Blocked by:** 01 — Load skills and resources by exact skill location.

**Status:** ready-for-agent

- [ ] Exercise a local skill and an in-memory custom-storage skill with the same declared name and different locations, document contents and guide contents. Both entries appear in the guidelines and can be selected independently through the public tools and repository.
- [ ] The custom-storage fixture models the proposed single-table database shape: skill_name, path and content, with a unique pair of skill_name and path. It interprets its own complete skill locations and requires no database service, credentials or production database adapter.
- [ ] Loading the custom-storage location returns its original skill document; reading its relative guide returns its guide rather than the local content. A declared name differing from the backend identifier remains supported.
- [ ] When a requested resource is absent from the selected custom storage but present in the local same-name skill, the tool reports the selected-source error and direct PHP access throws RuntimeException. No retry or fallback to the local skill occurs.
- [ ] Duplicate skill locations are rejected whether repeated within one adapter or across adapters. This is a configuration failure rather than name shadowing, a warning or a skipped document; unreadable document handling must not hide an ownership collision.
- [ ] A failed discovery does not publish part of the conflicting adapter's catalog or ownership claims. Subsequent public access must not silently succeed against a partially updated catalog. Verify observable behavior rather than private maps.
- [ ] Overlapping mount roots with distinct discovered skill locations remain usable. Routing is by exact catalog location, with neither prefix precedence nor mount-overlap rejection.
- [ ] Preserve diagnostics for ordinary invalid or unreadable skill documents and existing catalog lifecycle behavior, except for the intentional removal of same-name shadowing.
- [ ] Extend the existing multi-storage and toolkit integration tests using the public tool interfaces. Cover duplicate-location failure and exact selection through the public repository contract without introducing test-only seams.
- [ ] Custom-storage and multi-storage documentation explain location ownership, same-name coexistence, duplicate-location failure and the absence of resource fallback. Names are described as metadata, not unique selection keys.
- [ ] The repository's composer check command passes. This ticket does not implement production database/S3 storage, schema migrations or external provisioning.
