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

$projectMount = 'file://'.str_replace('%2F', '/', rawurlencode(__DIR__));
$toolkit = SkillToolkit::make()
    ->fromStorage(new FileSystemSkillStorage($projectMount.'/.agents/skills/'));

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
separately, relative to the skill root even when found in a supporting document;
do not compose resource URIs.
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
A `file:///` location is an address, not a native working directory. Validate
and decode its local path once before using it with an execution tool. Remote
locations do not imply that their scripts can be executed.

`FileSystemSkillStorage` accepts local absolute file URIs with an empty host,
such as `file:///app/skills/`. Other schemes, hosts (including `localhost`),
queries, fragments, backslashes and malformed percent escapes are rejected.
Percent-encode spaces, literal `%`, `#` and non-ASCII bytes in path segments:
`/app/my skills/café%/` becomes `file:///app/my%20skills/caf%C3%A9%25/`.
The examples encode native paths once while preserving `/` separators.

Mounts may omit the final slash. Dot segments and redundant separators are
normalized; discovery emits encoded skill-root addresses ending in `/`, without
`SKILL.md`. Copy those addresses exactly: alternate URI spellings are not lookup
aliases. Resource paths are separate native relative paths, so a resource named
`notes%20.md` is requested as `notes%20.md`, without URI decoding. Parent segments
are allowed when the resolved target remains inside the selected skill.

A skill directory may be a symlink, including one pointing outside the storage
root. Its public location stays under the configured mount, while its canonical
native directory defines the resource boundary. Resource symlinks inside that
boundary work; links and paths escaping it fail. Reads return UTF-8 text only.

## Multiple Skill Directories

Configure the storages before registering the toolkit on your agent. Pass them
as separate sources. For example, combine bundled skills
with skills installed by the CLI:

```php
$projectMount = 'file://'.str_replace('%2F', '/', rawurlencode(__DIR__));
$toolkit = SkillToolkit::make()
    ->fromStorage(
        new FileSystemSkillStorage($projectMount.'/skills/'),
        new FileSystemSkillStorage($projectMount.'/.agents/skills/'),
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
Overlapping mount roots are allowed when their discovered skill locations are
distinct: selection uses the exact catalog address, without prefix precedence.

Restart the agent or recreate the toolkit after adding skills to an existing
directory: each storage is discovered on first access and its catalog is then
reused.

## Accessing Skills Directly

Share one `SkillRepository` between the toolkit and other application features,
for example slash commands and explicit skill invocation. `catalog()` returns a
list of `Skill` objects; `get($location)` returns the selected skill or throws a
`RuntimeException` when that exact location is unavailable.

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$projectMount = 'file://'.str_replace('%2F', '/', rawurlencode(__DIR__));
$skills = new SkillRepository(
    new FileSystemSkillStorage($projectMount.'/.agents/skills/'),
);
$agent->addTool(new SkillToolkit($skills));

foreach ($skills->catalog() as $skill) {
    echo $skill->name().': '.$skill->description().' ('.$skill->location().')';
}

$skill = $skills->get($projectMount.'/.agents/skills/caveman/');
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

- `list()` returns complete canonical skill-root locations, with a trailing slash.
- `read($location, $path)` reads UTF-8 text relative to the selected skill root.

Configure each adapter with its complete mount point as the first constructor
argument and backend dependencies separately. The adapter validates the mount,
encodes its skill locations and translates each location into its backend key.
Names in `SKILL.md` are metadata and need not match those keys. The repository
selects only exact discovered locations; it does not route by URI prefix.
Throw `RuntimeException` for expected read failures, such as missing or
unreadable resources. Empty text is valid. Reads must remain confined to the
selected skill, without falling back to another source.

## Database Storage

`DatabaseSkillStorage` reads from an existing PDO connection. Install PHP's PDO
extension and the driver for your database (`pdo_sqlite` for SQLite). The adapter
uses portable SQL; automated integration tests currently verify SQLite.

```php
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

$pdo = new PDO('sqlite:'.__DIR__.'/skills.sqlite');
$toolkit = SkillToolkit::make()->fromStorage(
    new DatabaseSkillStorage('db://team/', $pdo),
);
// Optional third argument: a different table in the same connection.
$archive = new DatabaseSkillStorage('db://archive/', $pdo, 'archived_skills');
```

The constructor takes the complete mount, the application's PDO connection and
an optional table name (default: `skills`). Mounts use `db://label/` with optional
nested segments, for example `db://team/project/`. Labels and segments contain
lowercase ASCII letters, digits or hyphens; a trailing slash is required.
Credentials, ports, queries, fragments and dot segments are not accepted.
Table names must be simple unqualified SQL identifiers: a letter or underscore,
followed by letters, digits or underscores. Choose a name that is not a reserved
word in your database.

The application creates and populates the table. For example, in SQLite:

```sql
CREATE TABLE skills (
    skill_name TEXT NOT NULL,
    path TEXT NOT NULL,
    content TEXT NOT NULL,
    UNIQUE (skill_name, path)
);
```

Each row contains one UTF-8 text resource. Store the complete skill document at
`SKILL.md` and resources at normalized, slash-separated relative paths such as
`references/guide.md`. Empty content is valid. Binary content is unsupported.
The application owns schema, uniqueness constraints and updates; the adapter
only reads and reports missing tables without creating them. Configure the
schema to preserve distinct identifier and path spellings, including case, when
enforcing uniqueness. The adapter must select the exact stored spelling even
when database comparison defaults are case-insensitive.

`skill_name` is a backend identifier; it may differ from the declared document
name. With mount `db://team/`, identifier `team caveman` is discovered at
`db://team/team%20caveman/`. Copy the catalog location verbatim into the tools and
pass `references/guide.md` as a separate path. Paths are native text, so literal
percent characters are not URI-decoded. Confined dot and parent segments work;
absolute paths and paths escaping the selected skill fail.

The mount labels every skill in the selected table. Two mounts over the same
connection and table expose the same rows under different locations. Use separate
tables or databases for data isolation; the mount does not filter tenants. A
filesystem skill with the same declared name remains independently selectable,
and a missing database resource never falls back to that filesystem skill.

Catalog metadata is discovered lazily and retained by the repository. Documents
and supporting resources are read on demand; recreate the repository to discover
new skills or update catalog metadata. PDO error modes are preserved. Expected
access failures are `RuntimeException` for PHP callers and readable tool results.

## Error Handling

Invalid or unreadable skill documents are skipped. Use `$skills->diagnostics()`
to inspect loading problems and warnings. Duplicate skill locations instead fail
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

## License

[MIT](LICENSE).
