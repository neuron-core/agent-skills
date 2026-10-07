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

The agent initially sees each skill's name, description and location. When a
skill is relevant to the task, it uses `skill` to load its instructions. If those
instructions reference supporting files, it can read them with `skill_resource`.
Copy the catalog location verbatim into either tool. Pass resource paths
separately as literal paths relative to the skill root, even when found in a
supporting document. For example, `references/my guide.md` selects that file;
`references/my%20guide.md` selects a file whose name contains `%20`.
Do not compose full resource URIs.
This keeps the initial context small while making the full skill available when
needed.

The toolkit registers two tools:

| Tool | Purpose |
| --- | --- |
| `skill` | Load the complete `SKILL.md` at a catalog `location`. |
| `skill_resource` | Read `path` relative to the skill root selected by `location`. |

Skills can also include scripts. To execute them, register an execution tool,
such as Neuron's `BashTool`, alongside the toolkit. The library supplies the
instructions and resource locations; your application controls execution.
Decode a local `file:///` location from the current catalog once with
`rawurldecode(substr($location, 7))` to obtain the native working directory
for an execution tool. Remote
locations do not imply that their scripts can be executed.

`FileSystemSkillStorage` accepts an absolute native directory such as
`/app/skills/`, or a local absolute file URI with an empty host such as
`file:///app/skills/`. Relative directories, other schemes, hosts (including `localhost`),
null bytes and backslashes are rejected. In a `file://` input, raw `?` and `#`
are treated as directory characters; the emitted location encodes them.
Percent-encode spaces, literal `%`, `#` and non-ASCII bytes in path segments:
`/app/my skills/café%/` becomes `file:///app/my%20skills/caf%C3%A9%25/`.
The directory must already exist. The constructor resolves it with `realpath()`,
including any symlinks in the configured root, and uses that absolute path to
generate file URI locations. Missing directories and regular files are rejected.

Filesystem base paths may omit the final slash. Dot segments and redundant separators are
normalized; discovery emits encoded skill-root addresses without a trailing slash or
`SKILL.md`. Copy those addresses exactly: alternate URI spellings are not lookup
aliases. Resource paths are literal: a file named `notes%20.md` is requested as
`notes%20.md`, while `notes .md` selects the file with a space. Dot and parent
segments are normalized relative to the skill root. Paths escaping that root
are rejected.

A skill directory may be a symlink, including one pointing outside the storage
root. Its public location stays under the storage's base URI, while its canonical
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

Skills with the same declared name remain available at distinct locations.
Names are descriptive metadata, not unique selection keys. Each discovered
location belongs to the adapter that listed it; instructions and resources stay
with that source. A missing resource fails even if another same-name skill has
that file.

Listing the same exact location twice is a configuration error, including within
one adapter or when registering the same adapter twice. Discovery throws a
`RuntimeException` and does not publish a partial catalog for the conflicting
adapter. Invalid or unreadable documents do not hide location conflicts.
Overlapping storage base URIs are allowed when their discovered skill locations are
distinct: selection uses the exact catalog address, without prefix precedence.

`FileSystemSkillStorage::list()` reads the directory when called. The repository
retains its catalog after first access, so restart the agent or recreate the
toolkit to include newly added skills there.

## Accessing Skills Directly

Share one `SkillRepository` between the toolkit and other application features,
for example slash commands and explicit skill invocation. `catalog()` returns a
list of `Skill` objects; `get($location)` returns the selected skill or throws a
`RuntimeException` when that exact location is unavailable.
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
    echo $skill->name().': '.$skill->description().' ('.$skill->location().')';
}

$matches = $skills->findByName('caveman');

$skill = $skills->catalog()[0];
$frontmatter = $skill->readFrontmatter();   // Parsed YAML metadata as stdClass.
$instructions = $skill->readInstructions(); // Body without YAML frontmatter.
$document = $skill->readDocument();         // Complete original SKILL.md.
$location = $skill->location();             // Complete non-null skill-root address.
$resource = $skill->readResource('references/guide.md');
```

Optional and extension metadata is preserved when a skill is loaded. Fields
such as `disable-model-invocation` and `user-invocable` are not enforced by this
library. Applications that depend on invocation restrictions must implement
them in their host agent.

## Custom Storage

Implement [`SkillStorageInterface`](src/Storage/SkillStorageInterface.php) to
load skills from another backend. It defines two methods:

- `list()` returns complete canonical skill-root locations, without a trailing slash.
- `read($location, $path)` reads content as a PHP string from a literal path relative to a catalog location.

Each adapter defines its base URI, encodes its skill locations and translates
each location into its backend key. Configure the filesystem adapter with an
existing absolute directory or `file://` URI. Configure the database adapter
with a PDO connection and an optional `baseUri`.
Names in `SKILL.md` are metadata and need not match those keys. The repository
selects only exact discovered locations; it does not route by URI prefix.
Throw `RuntimeException` for expected read failures, such as missing or
unreadable resources. Empty text is valid. Reads must remain confined to the
selected skill, without falling back to another source.

[`ResourceLocator`](src/ResourceLocator.php) creates canonical skill-root
locations and validates each location and literal resource path under one base URI.
Both storage adapters use it internally. The filesystem adapter converts its
local directory to a `file:///` base URI.

`Skill::readResource($path)` passes a literal path and the selected skill location
to its storage. Each storage validates the location and normalizes the path;
database storage looks up the normalized row key, while filesystem storage also
uses `realpath()` to resolve symlinks and keep the file inside the selected skill.

## Database Storage

`DatabaseSkillStorage` reads from an existing PDO connection. Install PHP's PDO
extension and the driver for your database (`pdo_sqlite` for SQLite). The adapter
uses portable SQL; automated integration tests currently verify SQLite.

```php
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$pdo = new PDO('sqlite:'.__DIR__.'/skills.sqlite');
$toolkit = SkillToolkit::make()->fromStorage(new DatabaseSkillStorage($pdo));
// Set a different base URI when several database storages share a toolkit.
$archive = new DatabaseSkillStorage($pdo, table: 'archived_skills', baseUri: 'db://archive/');
```

The constructor takes the application's PDO connection, an optional base URI
(default: `db://skills/`) and an optional table name (default: `skills`). PDO query results must expose the
lowercase column names `skill_identifier`, `path` and `content`.
Database base URIs use `db://label/` with optional
nested segments, for example `db://team/project/`. Labels and segments contain
lowercase ASCII letters, digits or hyphens; a trailing slash is required.
Credentials, ports, queries, fragments and dot segments are not accepted.
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
`RuntimeException`. With base URI `db://team/`, identifier `team caveman` is discovered at
`db://team/team%20caveman`. Identifier `literal%20name` becomes
`db://team/literal%2520name`, and `café` becomes `db://team/caf%C3%A9`.
Copy the catalog location verbatim into the tools and pass `references/guide.md`
as a separate literal relative path. Database `path` values remain literal names:
`references/my%20guide.md` in a call selects that exact stored key.
Confined dot and parent segments work; absolute paths and paths escaping the
selected skill fail.

The base URI labels every skill in the selected table. Two base URIs over the same
connection and table expose the same rows under different locations. Use separate
tables or databases for data isolation; the base URI does not filter tenants. A
filesystem skill with the same declared name remains independently selectable,
and a missing database resource never falls back to that filesystem skill.
For example, register both adapters on the same toolkit:

```php
$toolkit = SkillToolkit::make()->fromStorage(
    new FileSystemSkillStorage('file:///app/skills/'),
    new DatabaseSkillStorage($pdo, baseUri: 'db://team/'),
);
```

A local `caveman` and database `team caveman` may both declare `name: caveman`;
select their separate catalog locations to load each original document and its
own resources. Registering two database adapters that emit the same location
fails discovery as a duplicate-location configuration error.

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
to inspect loading problems and warnings; each entry contains `skillLocation`
and `message`. Duplicate skill locations instead fail
discovery as a configuration error; they are never skipped or treated as name
shadowing.

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

## Resource path migration

`Skill::readResource()` and `skill_resource` accept literal paths. Pass
`my guide.md` for a filename with a space, or `notes%20.md` for a filename
containing those exact characters.
If a caller previously passed URI-encoded resource paths, it must now pass the
decoded filename. Stored filenames and database rows do not change.

Custom storage implementations receive the catalog location and a literal resource
path in `read($location, $path)`. They must validate and confine both values before
backend lookup.


## License

[MIT](LICENSE).
