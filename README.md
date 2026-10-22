# Neuron AI Skills

[![Tests](https://github.com/neuron-core/agent-skills/actions/workflows/tests.yml/badge.svg)](https://github.com/neuron-core/agent-skills/actions/workflows/tests.yml)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4?logo=php&logoColor=white)](composer.json)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

> [!IMPORTANT]
> Get early access to new features, exclusive tutorials, and expert tips for building AI agents in PHP. Join a community of PHP developers pioneering the future of AI development.
> [Subscribe to the newsletter](https://neuron-ai.dev)

> Before moving on, support the Neuron AI community giving a GitHub star ⭐️. Thank you!

Add Agent Skills to your [Neuron AI](https://github.com/neuron-core/neuron-ai)
agents with `SkillToolkit`. Combine your own skills with community packages and
make them available to the agent through a single toolkit.

The library handles skill discovery and provides tools for loading instructions
and supporting resources when needed. It follows the open
[Agent Skills specification](https://agentskills.io/specification) and supports
local directories, PDO databases and custom storage.

![Neuron Agent Skills Package](docs/cover.png)

## Installation

Requires PHP 8.1+.

| Agent Skills | Neuron AI |
| --- | --- |
| 1.x (current) | 4.x |
| 0.x | 3.x |

```sh
composer require neuron-core/agent-skills
```

## Quick Start

Find community skills on [skills.sh](https://skills.sh) and install one from
your application's root:

```sh
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
```

The [Skills CLI](https://github.com/vercel-labs/skills) requires Node.js/npm and
installs `caveman` into `.agents/skills`. Create an agent and register that
directory, replacing `your-api-key` with your OpenAI API key:

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Chat\Messages\UserMessage;
use NeuronAI\Providers\OpenAI\OpenAI;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$toolkit = SkillToolkit::make()
    ->fromStorage(new FileSystemSkillStorage(__DIR__.'/.agents/skills'));

$agent = Agent::make()
    ->setThreadId('quick-start')
    ->setAiProvider(new OpenAI(key: 'your-api-key', model: 'gpt-5.4-nano'))
    ->addTool($toolkit);

$response = $agent->chat(new UserMessage(
    'Use caveman skill to explain how the universe works.',
));

echo $response->getMessage()->getContent();

// Actual response (excerpt):
// Cosmic history: big bang expansion. Early hot plasma cooled; atoms formed.
// Gravity pulled gas into stars, stars forged heavier elements. Supernovae
// spread elements; mergers build galaxies.
```

## How Skills Work

The agent initially sees one selected entry per declared name, containing only
the name and description. When a skill is relevant to the task, it uses `skill`
to load its instructions. If those
instructions reference supporting files, it can read them with `skill_resource`.
Pass the declared skill name in `name` to `skill`. When names repeat, the first
configured storage with a valid document wins; discovery order within that
storage breaks ties. Pass the same declared name in `name` to `skill_resource`,
with one literal `path` relative to the skill root. The result is the complete
file content as text, without a JSON wrapper, preserving Unicode and line endings.
For example, `references/my guide.md` selects that file;
`references/my%20guide.md` selects a file whose name contains `%20`.
Read a referenced file before following further required references named in
its contents. Copy those paths exactly; do not invent files or compose full URIs.
An unavailable or invalid path returns an error without falling back to another
same-name skill. Empty file content is valid.
This keeps the initial context small while making the full skill available when
needed.

The toolkit registers exactly three tools for any usable catalog, whether
filesystem-only, database-only or mixed. An empty usable catalog registers none:

| Tool | Purpose |
| --- | --- |
| `skill` | Load the complete Markdown instructions without YAML frontmatter, by declared `name`. |
| `skill_resource` | Read one literal `path` from the skill selected by declared `name`; return its full contents as text. |
| `skill_script_prepare` | Prepare every resource of the skill selected by `name`; return `directory` and relative `files`. |

The `skill` result prefixes nonempty instructions with `Instructions loaded.`;
the instruction body itself is unchanged.

Skills can also include scripts. Register a separate execution tool, such as
Neuron's `BashTool`, alongside the toolkit. For every storage source, the agent
loads the instructions without YAML frontmatter with `skill(name)`, reads necessary references with
`skill_resource(name, path)` and calls `skill_script_prepare(name)` before
executing scripts from the returned directory. It does not interpret skill URIs
or choose a preparation procedure by storage type. Ordinary document loading
and reference reading do not prepare files.

When a loaded document says to call another skill, the agent must call `skill`
with that declared name and read its document before continuing or asking
clarification questions. Describing a delegation does not load its instructions.
An explicit user invocation remains valid when the document includes
`disable-model-invocation: true`. Required supporting files are read in dependency
order through `skill_resource`, even when their storage is non-local.

Preparation returns the original native directory for storages implementing
`LocalSkillStorageInterface`, including filesystem storage. For other sources it copies every enumerated resource, including `SKILL.md`, scripts,
configuration, references and binary assets, into a fresh temporary directory.
Relative paths and exact bytes are preserved. A read or write failure returns an
error, never a successful incomplete directory. Preparation does not execute
code, install packages or grant execution permissions. Each skill specifies
its input and output requirements; the separate execution tool must be able to
access the returned directory in the application process's filesystem.

Temporary directories are created under `sys_get_temp_dir()` and remain after
execution. Their cleanup belongs to the application or environment, including
partial files left after failed preparation. The toolkit provides no cleanup
method, shutdown callbacks, session lifecycle or caching of prepared copies.
Original skill files are preserved.

`FileSystemSkillStorage` accepts an absolute native directory such as
`/app/skills/`, or a local absolute file URI with an empty host such as
`file:///app/skills/`. Relative directories, other schemes, hosts (including `localhost`),
null bytes and backslashes are rejected. In a `file://` input, raw `?` and `#`
are treated as directory characters. Percent-encode literal `%` and non-ASCII
bytes when supplying a file URI; native paths need no encoding. The directory
must already exist. The constructor resolves it with `realpath()`, including
symlinks in the configured root. Missing directories and regular files are rejected.

Filesystem base paths may omit the final slash. Dot segments and redundant
separators are normalized. Discovery returns literal directory names as skill
identifiers, with no URI encoding.
Resource paths are literal: a file named `notes%20.md` is requested as
`notes%20.md`, while `notes .md` selects the file with a space. Dot and parent
segments are normalized relative to the skill root. Paths escaping that root
are rejected.

A skill directory may be a symlink, including one pointing outside the storage
root. Its identifier remains the directory entry's name, while its canonical
native directory defines the resource boundary. Resource symlinks inside that
boundary work; links and paths escaping it fail. Reads return the file bytes as a
PHP string.

## Multiple Skill Directories

Configure the storages before registering the toolkit on your agent. Pass them
as separate sources. For example, combine bundled skills
with skills installed by the CLI:

```php
$toolkit = SkillToolkit::make()
    ->fromStorage(
        new FileSystemSkillStorage(__DIR__.'/skills'),
        new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
    );
```

The internal repository retains every valid skill with its owning storage and
storage-specific identifier. Agent-facing tools and catalog select the first
valid skill in configured storage order, sorting identifiers within each storage.
Declared names may differ from directory names or database identifiers. All three
tools keep instructions, resources and preparation with that selected source.
A missing resource fails even if another same-name skill has that file.

Equal identifiers in different storages are allowed. Repeated identifiers within
one storage's `list()` result are a configuration error, including when their
documents are invalid or unreadable. Discovery throws a `RuntimeException` and
does not publish a partial catalog for that storage.

`FileSystemSkillStorage::list()` reads the directory when called. The repository
retains its catalog after first access, so restart the agent or recreate the
toolkit to include newly added skills there.

## Accessing Skills Directly

Share one `SkillRepository` between the toolkit and other application features,
for example slash commands and explicit skill invocation. `catalog()` returns a
list of `Skill` objects, each retaining its owning storage and identifier.
`getByName($name)` returns the first valid skill in configured storage and
discovery order, or throws when the name is unknown.
`findByName($name)` returns all catalog skills with that exact declared name as a
list of `Skill` objects, or `[]` when none match. The search is case sensitive;
skills with the same name remain separate results.

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$skills = new SkillRepository(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
$agent->addTool(new SkillToolkit($skills));

foreach ($skills->catalog() as $skill) {
    echo $skill->name().': '.$skill->description().' ('.$skill->identifier().')';
}

$matches = $skills->findByName('caveman');

$skill = $skills->catalog()[0];
$frontmatter = $skill->loadFrontmatter();   // Parsed YAML metadata as stdClass.
$instructions = $skill->loadInstructions(); // Body without YAML frontmatter.
$document = $skill->loadDocument();         // Complete original SKILL.md.
$identifier = $skill->identifier();        // Key within the owning storage.
$storage = $skill->storage();              // Owning storage instance.
$resource = $skill->resource('references/guide.md'); // Bound to this skill's source.
$contents = $resource->load();              // Reads current bytes; no cache.
foreach ($skill->resources() as $resource) {
    echo $resource->path();
    $contents = $resource->load();
}
```

Optional and extension metadata is preserved when a skill is loaded. Fields
such as `disable-model-invocation` and `user-invocable` are not enforced by this
library. Applications that depend on invocation restrictions must implement
them in their host agent.

## Custom Storage

Implement [`SkillStorageInterface`](src/Storage/SkillStorageInterface.php) to
load skills from another backend. It defines exactly three methods:

- `list()` returns unique, non-empty skill identifiers scoped to this storage.
- `resources($skillIdentifier)` returns all literal relative file paths belonging to that skill, including `SKILL.md`, scripts, configuration, references and assets. Enumeration remains confined to the skill root, includes files rather than directories and must bound traversal through links.
- `read($skillIdentifier, $path)` reads content as a PHP string from a literal path relative to that skill's root.

The repository parses `SKILL.md` to build the catalog. Names declared in the
document need not match storage identifiers. Reads use the owning storage object
and its identifier directly; no base URI or location is needed.
Throw `RuntimeException` for expected failures such as missing or unreadable
resources. Empty text is valid. Reads must remain confined to the selected skill,
without falling back to another source.

Storages with files available locally can additionally implement
[`LocalSkillStorageInterface`](src/Storage/LocalSkillStorageInterface.php). Its
`directory($skillIdentifier)` method returns the existing absolute native skill
directory accessible to the execution tool. Script preparation uses that directory
and the storage's resource inventory; other storages are copied to a temporary
directory. `FileSystemSkillStorage` implements this interface.

Both built-in adapters use [`StoragePaths`](src/StoragePaths.php) to validate
single-segment identifiers and normalize literal resource paths without URI
encoding or decoding. `validateIdentifier($skillIdentifier)` validates an identifier;
`normalizePath($path)` returns a normalized relative path. These operations do
not access storage or create resource objects.

`Skill::resource($path)` returns a [`Resource`](src/Resource.php) bound to the
owning storage and skill identifier. Creating it does not read or check the
resource; `load()` delegates to the storage on every call, preserving empty and
binary content and propagating expected `RuntimeException` failures.
`path()` returns the supplied literal relative path; normalization happens in
the built-in storages during loading. `Skill::resources()` wraps the storage's
file inventory as a list of these resource objects.
Filesystem storage additionally uses `realpath()` to
resolve symlinks and keep each file inside the selected skill.

## Database Storage

`DatabaseSkillStorage` reads from an existing PDO connection. Install PHP's PDO
extension and the driver for your database (`pdo_sqlite` for SQLite). The adapter
uses portable SQL; automated integration tests currently verify SQLite.

```php
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$pdo = new PDO('sqlite:'.__DIR__.'/skills.sqlite');
$toolkit = SkillToolkit::make()->fromStorage(new DatabaseSkillStorage($pdo));
// Select another application-owned table.
$archive = new DatabaseSkillStorage($pdo, table: 'archived_skills');
```

The constructor takes the application's PDO connection and an optional table
name (default: `skills`). PDO query results must expose the lowercase column names
`skill_identifier`, `path` and `content`.
Table names must be simple unqualified SQL identifiers: a letter or underscore,
followed by letters, digits or underscores. Choose a name that is not a reserved
word in your database.

The application creates and populates the table. For example, in SQLite:

```sql
CREATE TABLE skills (
    skill_identifier TEXT NOT NULL,
    path TEXT NOT NULL,
    content TEXT NOT NULL,
    UNIQUE (skill_identifier, path)
);
```

When updating an existing application-owned table from the earlier schema,
rename `skill_name` to `skill_identifier` before using this adapter. The library
does not migrate tables automatically.

Each row contains one resource. Store the complete UTF-8 skill document at
`SKILL.md` and resources at normalized, slash-separated relative paths such as
`references/guide.md`. Empty content is valid. The database adapter returns the
fetched `content` value directly, without checking its encoding or binary bytes.
The application owns schema, uniqueness constraints and updates; the adapter
only reads and reports missing tables without creating them. The schema must
compare both `skill_identifier` and `path` exactly in queries and uniqueness
constraints. For example, `caveman` and `Caveman`, or `guide.md` and `Guide.md`,
must remain distinct. SQLite's default `BINARY` collation meets this requirement;
configure equivalent comparisons in other databases. The adapter relies on the
database comparison and returns the row selected by the query.

`skill_identifier` is a non-empty, single-segment backend identifier; it may differ from the
declared document name. An empty identifier causes discovery to fail with a
`RuntimeException`. Identifiers such as `team caveman`, `literal%20name` and `café`
remain unchanged during discovery and reads.
Pass the declared name to the tools and `references/guide.md` as a literal
relative path to `skill_resource`. Use `getByName()` or `findByName()` for direct
repository access. Database `path` values remain literal names:
`references/my%20guide.md` in a call selects that exact stored key.
Confined dot and parent segments work; absolute paths and paths escaping the
selected skill fail.

The owning PDO connection and table determine the source. Use separate tables or
databases for data isolation. Equal identifiers in separate storage instances
remain distinct catalog entries. A filesystem skill with the same declared name
also remains in the internal catalog; agent-facing selection uses configured
storage order, and a missing database resource never falls back to that skill.
For example, register both adapters on the same toolkit:

```php
$toolkit = SkillToolkit::make()->fromStorage(
    new FileSystemSkillStorage('file:///app/skills/'),
    new DatabaseSkillStorage($pdo),
);
```

A local `caveman` and database `team caveman` may both declare `name: caveman`;
the tools select the local skill in this configuration. Direct repository
access can still load both original documents and their own resources through the
`Skill` objects returned by `findByName('caveman')`.

Catalog metadata is discovered lazily and retained by the repository. Documents
and supporting resources are read on demand; recreate the repository to discover
new skills or update catalog metadata. Updated text is visible on the next read;
deleted documents and resources fail even while their catalog metadata remains.
PDO connection settings are preserved. The adapter expects PDO's default
`PDO::ERRMODE_EXCEPTION` error mode and `content` fetched as a string; settings
that convert empty strings to `null` are not supported.
Expected access failures are `RuntimeException` for PHP callers and readable
tool results.

## Error Handling

Invalid or unreadable skill documents are skipped. Use `$skills->diagnostics()`
to inspect loading problems and warnings; each entry contains `storage`,
`skillIdentifier` and `message`. Repeated identifiers within one storage’s
`list()` result instead fail discovery as a configuration error.

The tools report read failures to the agent. When accessing skills directly,
catch `RuntimeException` for unavailable skills, documents or resources.

## Runnable Examples

See the [interactive demo guide](examples/README.md) for setup instructions and
sample conversations using skills and supporting resources.

## Contributing

Report bugs and propose changes through [GitHub Issues](https://github.com/neuron-core/agent-skills/issues)
and pull requests. From the repository root, run the development checks with:

```sh
composer install
composer check
```

`composer check` runs PHPUnit and PHPStan without requiring an API key.
Development requires PDO and `pdo_sqlite` for the real in-memory SQLite tests.
CI covers PHP 8.1–8.5, multiple Neuron AI versions and Symfony YAML compatibility.

## Tool interface migration

`skill_resource(name, path)` replaces `skill_resources(name, paths)` and
`SkillResourceTool` replaces `SkillResourcesTool`. Update tool registrations,
scripted calls and result handling: issue one call per file and consume the
returned text directly instead of decoding a resources collection. The old tool
and class are removed without compatibility aliases. The `skill` and
`skill_script_prepare` inputs remain unchanged.

## Resource path migration

`Skill::resource()` and `skill_resource` accept literal paths. Pass
`my guide.md` for a filename with a space, or `notes%20.md` for a filename
containing those exact characters.
If a caller previously passed URI-encoded resource paths, it must now pass the
decoded filename. Stored filenames and database rows do not change.

## Storage interface migration

Storage methods now use backend identifiers rather than canonical skill locations.
Remove the `baseUri` argument from `DatabaseSkillStorage`, return identifiers from
custom `list()` implementations and accept them in `resources()` and `read()`.
`StoragePaths` provides `validateIdentifier($skillIdentifier): void` and
`normalizePath($path): string`, without a base URI or intermediate reference
object. `resolve()`, `fromSkillIdentifier()` and `ResourceReference` are removed.
`Skill::resources()` returns `Resource` objects rather than paths; use
`$resource->path()` for the path and `$resource->load()` for the content.
The storage interface's `resources()` method continues to return paths.
Replace `Skill::readResource($path)` calls with `resource($path)->load()`.

`Skill::identifier()` replaces `location()`, and `storage()` exposes the owner.
`SkillRepository::get($location)` is removed: use `getByName($name)` for the
selected skill or retain a `Skill` from `catalog()` or `findByName()` to access a
specific source. Diagnostics now expose `storage` and `skillIdentifier` instead
of `skillLocation`. Custom storages that previously exposed local files through
`file://` locations must implement `LocalSkillStorageInterface` to keep preparing
scripts in their original directory.


## License

[MIT](LICENSE).
