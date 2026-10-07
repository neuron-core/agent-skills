# Try the skills demo

For a local SQLite storage check without model credentials or network calls,
run `vendor/bin/phpunit tests/DatabaseExampleTest.php`.

One interactive agent loads `php-check` from `skills/`, `caveman` from
`.agents/skills/`, and `dante` from `skills.sqlite`.

## Setup

You need PHP 8.1+, Composer, Node.js/npm and an OpenAI API key. For script
execution, the PHP CLI needs `curl`, `json` and `proc_open`. The demo makes real
API requests.

From the repository root:

```sh
composer install
php examples/sqlite-setup.php
cp examples/.env.example examples/.env
```

Set `OPENAI_API_KEY` in `examples/.env`. The default model is `gpt-5.4-nano`;
change `OPENAI_MODEL` if needed.

Install `caveman` **from `examples/`**, then start the chat:

```sh
cd examples
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
php demo.php
```

Run SQLite setup before starting the chat; `demo.php` expects `skills.sqlite`.

## Try this conversation

This example illustrates a conversation with location-based tool inputs;
wording, paths and results can vary on your machine.

```text
$ php demo.php
Available skills:
- php-check: ... (location: file:///app/examples/skills/php-check)
- caveman: ... (location: file:///app/examples/.agents/skills/caveman)
- dante: ... (location: db://skills/dante)
Use a skill when the user requests it or it is relevant to the task. ...

See examples/README.md for setup and scenarios.

Type a message, or 'exit' to quit.

> use caveman skill to explain the universe

[tool: skill {"location":"file:///app/examples/.agents/skills/caveman"}]

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

[tool: skill {"location":"file:///app/examples/skills/php-check"}]

[tool: skill_resource {"location":"file:///app/examples/skills/php-check","path":"references/checks.md"}]

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
pass literal resource paths separately. The execution tool uses the decoded
native local path as its working directory, not the `file:///` URI. Use
`rawurldecode(substr($location, 7))` to decode a local catalog location once:
`file:///app/my%20skills/check%2520` has the native directory
`/app/my skills/check%20`. This lets relative script paths and neighboring assets
resolve from the skill directory. Remote locations do not provide local execution
access. The skill tools themselves only read text.

The demo passes absolute directories under `__DIR__` to `FileSystemSkillStorage`,
which constructs file base URIs internally. These use an empty host and an
absolute path. See the
[filesystem URI rules](../README.md#how-skills-work) for accepted forms and
symlink boundaries.

Script output contains `PHP_VERSION`, `CURL_EXTENSION`, `JSON_EXTENSION` and
`PROC_OPEN`; the agent explains any failed checks.

## Try a writing skill

The SQLite `dante` skill has one short reference file. Start
`php demo.php` and ask: **“Usa dante: scrivi una terzina
su un viandante nella selva.”**

The trace should show `skill` with location `db://skills/dante`, followed by
`skill_resource` with the same location and path `references/terzina.md`.
The second verse should contain `lanterna di rame`, which appears only in the
database reference.

Type `exit` or `quit` to stop. Restart the script after installing new skills.
For integration into your application, see the [Quick Start](../README.md#quick-start).

## SQLite storage demo

The setup uses PHP 8.1+, Composer, PDO and the `pdo_sqlite` driver. From the
repository root:

```sh
composer install
php examples/sqlite-setup.php
php examples/demo.php
```

Setup creates `examples/skills.sqlite`, an ignored local file. It accepts an
optional database filename as its first argument. The interactive demo reads
the default `examples/skills.sqlite`. Use a dedicated demo database. Rerunning
setup replaces the two `dante` resources and removes any old `demo-errors` rows.

An existing demo database using the old `skill_name` column needs that column
renamed to `skill_identifier` before rerunning setup; alternatively, use a new
database filename with both commands.

The application-side setup creates the default `skills` table with `skill_identifier`,
`path` and `content`. Its composite primary key enforces uniqueness of
`(skill_identifier, path)`, using SQLite's binary collation to preserve case-sensitive
identities. All columns are non-null text. `SKILL.md` and
`references/terzina.md` are rows in that same table. The backend identifier
and declared skill name are both `dante`. The SKILL.md instructs the agent to
read the reference before writing; its unique image appears only there.

The interactive demo constructs its own PDO connection and passes it to
`new DatabaseSkillStorage($pdo)`. The agent's `skill` and
`skill_resource` tools then read the document and its reference.

The default base URI `db://skills/` is a public address for all skills in the selected
table. It is neither a connection string nor a tenant filter. Two base URIs over
the same connection and table expose the same rows at different addresses. Use
separate tables or databases for data isolation. To read an application-managed
alternative table, pass its name using the `table` argument:

```php
$storage = new DatabaseSkillStorage($pdo, table: 'team_skills', baseUri: 'db://team/');
```

The application owns schema creation, uniqueness, case-sensitive identity rules
and data updates. The adapter discovers skills and returns the fetched `content`
string; it never creates tables or populates them. PDO is its connection dependency, together
with your database's PDO driver. Adapter queries target portable SQL, while this
setup script's DDL and `INSERT OR REPLACE` are SQLite-specific demonstration
code. Automated verification uses real SQLite; other PDO engines are not yet
verified, and the setup script is not a cross-database migration framework.

Database locations expose content through the read tools. They do not provide
local script access or execution, materialize files, or offer a dedicated binary
transfer tool. The
filesystem demo and its existing skill documents remain separate.

Run the example's automated checks with:

```sh
vendor/bin/phpunit tests/DatabaseExampleTest.php
```
