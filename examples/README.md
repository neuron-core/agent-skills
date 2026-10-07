# Try the skills demos

Three interactive examples use the same chat runner (`demo-chat.php`) and
configure different skill storages:

| Example | Storages | Skills |
| --- | --- | --- |
| `filesystem.php` | Two filesystem directories: `skills/` and `.agents/skills/` | `php-check`, `caveman` |
| `database.php` | One SQLite database: `text-stats.sqlite` | `text-stats` |
| `multiple.php` | Two filesystem directories and two SQLite databases | `php-check`, `caveman`, `dante`, `text-stats` |

## Setup

You need PHP 8.1+, Composer and an OpenAI API key. SQLite examples also need PDO
and `pdo_sqlite`. The PHP check script checks `curl`, `json` and `proc_open`.
The text statistics script needs Python 3, with no additional packages.
The interactive demos make real API requests.

From the repository root:

```sh
composer install
php examples/sqlite-setup.php
cp examples/.env.example examples/.env
```

Set `OPENAI_API_KEY` in `examples/.env`. The default model is `gpt-5.4-nano`;
change `OPENAI_MODEL` if needed.

For the filesystem and mixed examples, install `caveman` from `examples/`:

```sh
cd examples
npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
```

Type `exit` or `quit` to stop a demo. Restart after installing new skills.
The runner prints tool calls so you can inspect skill selection, resource reads
and script execution. For `bash` results it prints only the status: `success`,
`error`, or `returned` if no explicit status is available. It does not print result
contents or results from other tools.

## 1. Filesystem only

From the repository root:

```sh
php examples/filesystem.php
```

Ask: **“Usa php-check e spiegami i risultati.”**

The trace should show `skill` with the filesystem location for `php-check`,
`skill_resource` with path `references/checks.md`, then `bash` executing
`php scripts/check.php` from the skill directory. The script reports
`PHP_VERSION`, `CURL_EXTENSION`, `JSON_EXTENSION` and `PROC_OPEN`.

Filesystem catalog locations are `file:///` URIs. Execution tools need a native
path as their working directory. Decode the catalog location once with
`rawurldecode(substr($location, 7))`; relative script paths then resolve from
that skill directory. The skill tools themselves only read text.

## 2. Database only

```sh
php examples/database.php
```

Ask: **“Usa text-stats per analizzare il testo Ciao città.”**

The catalog location in this example is `db://skills/text-stats`. The script is stored as the
`scripts/analyze.py` resource alongside `SKILL.md`. The skill simply tells the
agent to run the script and report its JSON output, without storage-specific
instructions.

For the exact text `Ciao città` (no final newline), expect:

```json
{"words": 2, "lines": 1, "characters": 10}
```

Words are whitespace-separated tokens. Lines follow Python's `splitlines()`:
empty input has zero lines and a final newline does not add an extra line.
Characters are Unicode code points, including spaces and line endings.

Database storage only exposes text through `skill` and `skill_resource`; it does
not materialize or execute scripts. To run the resource, the agent needs to read
it, save it to a temporary file, execute it with `python3` using the exact input,
and clean up the temporary file. Inspect the trace to see whether the agent
handles this without storage-specific instructions in the skill.

## 3. Two filesystems and two databases

```sh
php examples/multiple.php
```

The example configures four independent storages:

- `skills/`: `php-check` on the filesystem.
- `.agents/skills/`: the installed `caveman` skill on the filesystem.
- `skills.sqlite`: `dante`, at `db://skills/dante`.
- `text-stats.sqlite`: `text-stats`, at `db://text-stats/text-stats`.

Try these prompts in the same conversation:

1. **“Usa php-check e spiegami i risultati.”**
2. **“Usa caveman per spiegare l'universo.”**
3. **“Use dante: write a tercet about a traveller crossing a forest.”**
4. **“Usa text-stats per analizzare il testo Ciao città.”**

For `dante`, the trace should show `skill_resource` reading
`references/terzina.md`. The second verse should contain `copper lantern`,
which appears only in that database reference.

## SQLite setup and local verification

`sqlite-setup.php` creates two ignored local databases. It accepts optional paths:

```sh
php examples/sqlite-setup.php /tmp/dante.sqlite /tmp/text-stats.sqlite
```

The demos use the default paths under `examples/`. Setup replaces two resources
in each database, and removes old `demo-errors` rows from `skills.sqlite`.
An older demo database with a `skill_name` column needs that column renamed to
`skill_identifier`, or must be recreated before running setup.

The application owns schema creation and updates. Each database has a `skills`
table with non-null `skill_identifier`, `path` and `content` columns, and a
composite primary key on `(skill_identifier, path)` with binary collation.
`DatabaseSkillStorage` only discovers skills and reads resource content.
The setup's DDL and `INSERT OR REPLACE` are SQLite-specific; they are not a
cross-database migration framework.

Base URIs identify public locations, not connections or tenant filters. The
mixed example uses distinct databases and distinct base URIs. Different URIs
alone would not isolate rows in the same database and table.

Run the local checks without model credentials or network calls:

```sh
vendor/bin/phpunit tests/DatabaseExampleTest.php
```

They verify database reads and execute the stored Python script from a temporary
file against Unicode, multiline, empty and final-newline inputs. They require
`python3`. They do not verify a live model's execution choices.

For application integration, see the [Quick Start](../README.md#quick-start).
