# 03: Support filesystem URI edge cases and preserve local script execution

**What to build:** Skills installed in directories with URI-sensitive names or supported symlink layouts remain discoverable and readable through catalog locations. Resource reads cannot escape the selected skill. When a local skill requests a script, the example and guidelines lead the host execution tool to a native working directory so the script and its neighboring assets continue to work.

**Blocked by:** 01 — Load skills and resources by exact skill location.

**Status:** resolved

- [x] Supported filesystem directory names requiring URI encoding round-trip from a configured file mount through discovery, guideline output, exact-location selection and document/resource reads. Cover spaces, literal percent characters and non-ASCII names without double decoding.
- [x] Document the supported local file URI form and enforce scheme/structure validation consistently. Public locations are canonical skill-root addresses; the repository continues using exact strings rather than trying arbitrary alternate spellings or fetching arbitrary URIs.
- [x] Retain the distinction between public skill locations and canonical native filesystem targets. A supported skill directory symlink remains usable, including a target outside the storage root, while its own canonical directory establishes the resource confinement boundary.
- [x] Internal resource symlinks remain usable and resource symlinks escaping the selected skill are rejected. Invalid paths, absolute resource selectors and effective parent-directory escapes cannot select another skill or access arbitrary files.
- [x] Relative parent segments that resolve within the selected skill remain compatible with existing confinement behavior. Resource reads return PHP strings and do not repeatedly URI-decode relative path input.
- [x] Toolkit-level tests show successful reads using an encoded catalog location and readable failures for prohibited resource access. Reuse the filesystem test seam for native path, URI and real symlink cases rather than adding a public resolver solely for tests.
- [x] Guidelines distinguish a skill's file URI from an execution tool's native working directory. Remote skill locations are not presented as directly executable; the skill tools continue only reading text.
- [x] The bundled local execution example uses the new mount and location contract and supplies a valid native working directory to the existing host execution tool. A script with a neighboring asset runs successfully in an automated regression without a live model request.
- [x] Examples and usage documentation explain the URI/native-path distinction and relevant supported filesystem forms. No new generic execution framework or remote materialization flow is introduced.
- [x] The repository's composer check command passes. This ticket can proceed independently of ticket 02 after ticket 01 is complete.

## Answer

Filesystem mounts now reject malformed or unencoded URI characters and emit
canonical encoded skill locations. Regression coverage exercises encoded mount
and skill names (spaces, percent signs, Unicode and `#`), exact location matching,
literal percent resource paths, confined parent segments, absolute selectors,
and canonical symlink boundaries, including skills linked outside the mount.

The toolkit regression loads an encoded catalog location through the fake
provider, reads its document and guide, and reports prohibited parent, URI and
symlink reads. The existing execution regression now uses the real `BashTool`
with a native working directory decoded once from the discovered location;
a relative script reads its neighboring binary asset successfully without a
live model request.

The bundled PHP demo and README mount examples encode the native checkout path.
Guidelines and usage docs explain exact locations, relative resource paths,
symlink confinement and the URI/native execution distinction. Per the user's
instruction, `examples/skills/php-check/SKILL.md` is unchanged from the original
baseline; execution guidance belongs to the toolkit and example documentation.

Validation: `composer check` passes (PHPUnit and PHPStan). A demo startup smoke
test in a temporary checkout containing spaces, `%`, `#` and Unicode discovered
`php-check` and exited cleanly, without making a model request. The filesystem,
encoded-catalog and host-execution regressions live in
`tests/Storage/FileSystemSkillStorageTest.php` and `tests/SkillToolkitTest.php`.
