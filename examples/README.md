# Try the skills demo

For a local example without model credentials or network calls, see the
[SQLite storage demo](#sqlite-storage-demo) below.

One interactive agent loads two skill sources: `php-check`, included in
`skills/`, and `caveman`, installed into `.agents/skills/` with the Skills CLI.

## Setup

You need PHP 8.1+, Composer, Node.js/npm and an OpenAI API key. For script
execution, the PHP CLI needs `curl`, `json` and `proc_open`. The demo makes real
API requests.

From the repository root:

```sh
composer install
cp examples/.env.example examples/.env
```

Set `OPENAI_API_KEY` in `examples/.env`. The default model is `gpt-5.4-nano`;
change `OPENAI_MODEL` if needed.

Install `caveman` **from `examples/`**, then start the chat:

```sh
cd examples
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
php agent-loop.php
```

The startup list should include `php-check` and `caveman`.

## Try this conversation

This example illustrates a conversation with location-based tool inputs;
wording, paths and results can vary on your machine.

```text
$ php agent-loop.php
Available skills: php-check, caveman

Try php-check for runtime checks or caveman for terse answers.
See examples/README.md for setup and both scenarios.

Type a message, or 'exit' to quit.

> use caveman skill to explain the universe

[tool: skill {"location":"file:///app/examples/.agents/skills/caveman/"}]

Universe explain: space-time is a dynamical stage; matter/energy tells it how to curve; curvature tells matter/energy how to move.

Big picture chain:
- At smallest scales: quantum fields. Particles = field excitations.
- Laws: gauge symmetry + quantum rules (Standard Model for known forces).
- Gravity: usually modeled by general relativity (space-time curvature).
- Early universe: hot, dense; rapid expansion (Big Bang model).
- Structure growth: tiny density fluctuations → gravity collapse → galaxies/clusters.
- Observations: expansion + CMB + light-element abundances support the model.
- Fate: expansion keeps accelerating → dark energy term (unknown physics).

“Why” it all exists: no confirmed answer; physics gives rules, not ultimate cause.

> nice!

Good.

> now use php-check skill and explain the results

[tool: skill {"location":"file:///app/examples/skills/php-check/"}]

[tool: skill_resource {"location":"file:///app/examples/skills/php-check/","path":"references/checks.md"}]

[tool: bash {"command":"php scripts/check.php","working_directory":"/app/examples/skills/php-check"}]

PHP-check results (facts about *this* PHP CLI run):

- **PHP 8.1+**: `PHP_VERSION=8.5.8` ✅ pass
  Next step: none.

- **curl extension**: `CURL_EXTENSION=loaded` ✅ pass
  Next step: none.

- **json extension**: `JSON_EXTENSION=loaded` ✅ pass
  Next step: none.

- **proc_open**: `PROC_OPEN=available` ✅ pass
  Next step: none.

All required checks pass, so the PHP CLI environment this script used meets the prerequisites. (It still doesn’t verify app config, network access, or whether an external API call succeeds.)

> fine

ok

> quit
```

`skill` loads the instructions, `skill_resource` reads the reference, and `bash`
executes the bundled script. The tools copy the complete catalog location and
pass resource paths separately. The execution tool uses the decoded native local
path as its working directory, not the `file:///` URI. Decode a validated local
location once: `file:///app/my%20skills/check%2520/` has the native directory
`/app/my skills/check%20/`. This lets relative script paths and neighboring assets
resolve from the skill directory. Remote locations do not provide local execution
access. The skill tools themselves only read text.

The demo constructs complete file mounts directly from `__DIR__`. File mounts
use an empty host and an absolute path; hosts, queries and fragments are
unsupported. See the
[filesystem URI rules](../README.md#how-skills-work) for accepted forms and
symlink boundaries.

Script output contains `PHP_VERSION`, `CURL_EXTENSION`, `JSON_EXTENSION` and
`PROC_OPEN`; the agent explains any failed checks.

Type `exit` or `quit` to stop. Restart the script after installing new skills.
For integration into your application, see the [Quick Start](../README.md#quick-start).

## SQLite storage demo

This separate example uses PHP 8.1+, Composer, PDO and the `pdo_sqlite` driver.
It runs locally without an API key, a model, Node.js or a remote database.
From the repository root:

```sh
composer install
php examples/database/setup.php
php examples/database/demo.php
```

Setup creates `examples/database/skills.sqlite`, an ignored local file. Both
commands accept an optional database filename as their first argument; pass the
same filename to each. Use a dedicated demo database. Rerunning setup replaces
the two demo resources and leaves other rows in place.

The application-side setup creates the default `skills` table with `skill_name`,
`path` and `content`. Its composite primary key enforces uniqueness of
`(skill_name, path)`, using SQLite's binary collation to preserve case-sensitive
identities. All columns are non-null text. `SKILL.md` and
`references/guide.md` are rows in that same table. The backend identifier is
`editorial`; the declared skill name is `clear-writing`.

The demo constructs its own PDO connection and passes it to
`new DatabaseSkillStorage('db://demo/', $pdo)`. It prints the toolkit's catalog,
including `db://demo/editorial/`, then calls the public `skill` and
`skill_resource` tools directly. The first returns the complete original
document, including frontmatter; the second uses that catalog location and the
separate relative path `references/guide.md` to return the writing guide.
No model is needed to demonstrate these tool calls.

The complete mount `db://demo/` is a public address for all skills in the selected
table. It is neither a connection string nor a tenant filter. Two mounts over
the same connection and table expose the same rows at different addresses. Use
separate tables or databases for data isolation. To read an application-managed
alternative table, pass its name as the third argument:

```php
$storage = new DatabaseSkillStorage('db://team/', $pdo, 'team_skills');
```

The application owns schema creation, uniqueness, case-sensitive identity rules
and data updates. The adapter discovers skills and reads UTF-8 text; it never
creates tables or populates them. PDO is its connection dependency, together
with your database's PDO driver. Adapter queries target portable SQL, while this
setup script's DDL and `INSERT OR REPLACE` are SQLite-specific demonstration
code. Automated verification uses real SQLite; other PDO engines are not yet
verified, and the setup script is not a cross-database migration framework.

Database locations provide text access only. They do not provide local script
access or execution, materialize files, or deliver binary resources. The
filesystem demo and its existing skill documents remain separate.

Run the example's automated checks with:

```sh
vendor/bin/phpunit tests/DatabaseExampleTest.php
```
