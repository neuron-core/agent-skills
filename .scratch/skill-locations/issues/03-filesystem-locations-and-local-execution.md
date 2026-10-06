# 03: Support filesystem URI edge cases and preserve local script execution

**What to build:** Skills installed in directories with URI-sensitive names or supported symlink layouts remain discoverable and readable through catalog locations. Resource reads cannot escape the selected skill. When a local skill requests a script, the example and guidelines lead the host execution tool to a native working directory so the script and its neighboring assets continue to work.

**Blocked by:** 01 — Load skills and resources by exact skill location.

**Status:** ready-for-agent

- [ ] Supported filesystem directory names requiring URI encoding round-trip from a configured file mount through discovery, guideline output, exact-location selection and document/resource reads. Cover spaces, literal percent characters and non-ASCII names without double decoding.
- [ ] Document the supported local file URI form and enforce scheme/structure validation consistently. Public locations are canonical skill-root addresses; the repository continues using exact strings rather than trying arbitrary alternate spellings or fetching arbitrary URIs.
- [ ] Retain the distinction between public skill locations and canonical native filesystem targets. A supported skill directory symlink remains usable, including a target outside the storage root, while its own canonical directory establishes the resource confinement boundary.
- [ ] Internal resource symlinks remain usable and resource symlinks escaping the selected skill are rejected. Invalid paths, absolute resource selectors and effective parent-directory escapes cannot select another skill or access arbitrary files.
- [ ] Relative parent segments that resolve within the selected skill remain compatible with existing confinement behavior. Resource reads remain text-only and do not repeatedly URI-decode relative path input.
- [ ] Toolkit-level tests show successful reads using an encoded catalog location and readable failures for prohibited resource access. Reuse the filesystem test seam for native path, URI and real symlink cases rather than adding a public resolver solely for tests.
- [ ] Guidelines distinguish a skill's file URI from an execution tool's native working directory. Remote skill locations are not presented as directly executable; the skill tools continue only reading text.
- [ ] The bundled local execution example uses the new mount and location contract and supplies a valid native working directory to the existing host execution tool. A script with a neighboring asset runs successfully in an automated regression without a live model request.
- [ ] Examples and usage documentation explain the URI/native-path distinction and relevant supported filesystem forms. No new generic execution framework or remote materialization flow is introduced.
- [ ] The repository's composer check command passes. This ticket can proceed independently of ticket 02 after ticket 01 is complete.
