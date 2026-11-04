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
    ->setAiProvider(new OpenAI(key: 'your-api-key', model: 'gpt-5.4-mini'))
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

The agent starts with only the name and description of each skill. When a
skill is relevant, it loads the full instructions on demand:

| Tool | Purpose |
| --- | --- |
| `skill` | Load a skill's instructions by its declared `name`. |
| `skill_resource` | Read one supporting file, such as `references/guide.md`, from that skill. |
| `skill_script_prepare` | Make the skill's files available in a local directory before running its scripts. |

Skills can include scripts. The toolkit does not execute them: register an
execution tool such as Neuron's `BashTool` alongside it.

```php
use NeuronAI\Tools\Toolkits\FileSystem\BashTool;

$agent->addTool(new BashTool())->addTool($toolkit);
```

Filesystem skills run from their original directory. Skills from other storages
are copied to a new directory under `sys_get_temp_dir()`; the toolkit does not
delete these copies.

## Multiple Sources

Pass several storages to combine bundled skills, CLI-installed skills and
database skills:

```php
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;

$toolkit = SkillToolkit::make()->fromStorage(
    new FileSystemSkillStorage(__DIR__.'/skills'),
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
    new DatabaseSkillStorage(new PDO('sqlite:'.__DIR__.'/skills.sqlite')),
);
```

If two skills declare the same name, the agent gets the one from the first
storage. Its instructions and files always come from that source, with no
fallback to another skill with the same name.

Skills are discovered on first use and then cached. Recreate the toolkit to
pick up newly added skills.

## Database Storage

`DatabaseSkillStorage` reads skills from an existing PDO connection. Each row
stores one file of a skill; the complete skill document goes at path `SKILL.md`.
You create and populate the table. For example, in SQLite:

```sql
CREATE TABLE skills (
    skill_identifier TEXT NOT NULL,
    path TEXT NOT NULL,
    content TEXT NOT NULL,
    UNIQUE (skill_identifier, path)
);

INSERT INTO skills VALUES
    ('caveman', 'SKILL.md', '---
name: caveman
description: Answer in terse caveman speech.
---
Use short words. Drop articles.'),
    ('caveman', 'references/examples.md', 'Big rock fall. Many dino gone.');
```

```php
$storage = new DatabaseSkillStorage($pdo);                      // table "skills"
$archive = new DatabaseSkillStorage($pdo, table: 'archived_skills');
```

- `skill_identifier` groups the rows of one skill. It may differ from the
  `name` declared in `SKILL.md`.
- `skill_identifier` and `path` must be compared case-sensitively, as with
  SQLite's default `BINARY` collation.
- The adapter only reads: it never creates or migrates tables. Integration tests
  run on SQLite.

## Accessing Skills Directly

Share a `SkillRepository` between the toolkit and other features, such as slash
commands or a skill picker:

```php
use NeuronAI\AgentSkills\SkillRepository;

$skills = new SkillRepository(
    new FileSystemSkillStorage(__DIR__.'/.agents/skills'),
);
$agent->addTool(new SkillToolkit($skills));

foreach ($skills->catalog() as $skill) {
    echo $skill->name().': '.$skill->description().PHP_EOL;
}

$skill = $skills->getByName('caveman');      // Selected skill, throws if unknown.
$all = $skills->findByName('caveman');       // Every skill declaring this name.

$skill->loadInstructions();                  // Body without YAML frontmatter.
$skill->loadFrontmatter();                   // Parsed metadata as stdClass.
$skill->resource('references/guide.md')->load();

foreach ($skill->resources() as $resource) {
    echo $resource->path().PHP_EOL;
}
```

Frontmatter fields such as `disable-model-invocation` and `user-invocable` are
preserved but not enforced. Implement those restrictions in your application if
you need them.

## Custom Storage

Implement [`SkillStorageInterface`](src/Storage/SkillStorageInterface.php) to
load skills from another backend:

```php
interface SkillStorageInterface
{
    /** @return list<string> Skill identifiers in this storage. */
    public function list(): array;

    /** @return list<string> Relative paths of every file, including SKILL.md. */
    public function resources(string $skillIdentifier): array;

    public function read(string $skillIdentifier, string $path): string;
}
```

Throw `RuntimeException` for missing or unreadable files. If the files are
already on the local disk, also implement
[`LocalSkillStorageInterface`](src/Storage/LocalSkillStorageInterface.php) so
scripts run from the original directory instead of a temporary copy.
[`StoragePaths`](src/StoragePaths.php) helps validate identifiers and normalize
paths.

## Error Handling

Invalid or unreadable skills are skipped; inspect `$skills->diagnostics()` to
see why. Tools report read failures to the agent. When accessing skills
directly, catch `RuntimeException`.

## Runnable Examples

The [examples](examples/README.md) include interactive demos with filesystem,
database and mixed storages, including skills that run scripts.

## Contributing

Report bugs and propose changes through [GitHub Issues](https://github.com/neuron-core/agent-skills/issues)
and pull requests. Development requires PDO and `pdo_sqlite`:

```sh
composer install
composer test
composer analyse
```

Neither command requires an API key.

## License

[MIT](LICENSE).
