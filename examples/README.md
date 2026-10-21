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

The trace should show `skill` with name `php-check`,
`skill_resource` with name `php-check` and path `references/checks.md`, `skill_script_prepare` with name `php-check`, then `bash` executing
`php scripts/check.php` from the skill directory. The script reports
`PHP_VERSION`, `CURL_EXTENSION`, `JSON_EXTENSION` and `PROC_OPEN`.

All three tools are registered for the filesystem demo. `skill_resource(name, path)`
reads one supporting file and returns its full contents as text. `skill` and
`skill_resource` only read; `skill_script_prepare(name)` returns the original
native directory and all available relative resource paths. The separate
execution tool runs from that returned directory. No URI decoding or temporary
copy is required from the agent.

## 2. Database only

```sh
php examples/database.php
```

Ask: **“Usa text-stats per analizzare il testo Ciao città.”**

The agent selects the declared name `text-stats`. The skill stores
two collaborating Python scripts and a configuration file alongside `SKILL.md`:

```text
scripts/analyze.py       Counts text from stdin and returns JSON
scripts/report.py        Reads the first script's JSON and selects output metrics
config/statistics.json  Settings read independently by both scripts
```

Analyze the exact input text with `analyze.py`, then inspect its JSON for word,
line and character counts before supplying that JSON to `report.py`. Both scripts
locate the configuration relative to their own files.
`include_whitespace` controls whether the first script counts whitespace in
`characters`; `metrics` selects the fields returned by the second script.
The defaults include whitespace and report `words`, `lines` and `characters`.
All three resources must be present, with their relative paths preserved. The
skill describes the two-step workflow without storage or tool instructions.

For the exact text `Ciao città` (no final newline), expect:

```json
{"words": 2, "lines": 1, "characters": 10}
```

Words are whitespace-separated tokens. Lines follow Python's `splitlines()`:
empty input has zero lines and a final newline does not add an extra line.
Characters are Unicode code points, including spaces and line endings by
default. With `include_whitespace: false`, whitespace code points are excluded
from the character count; word and line counts retain the same definitions.

Database storage discovers skills, enumerates their resources and reads bytes;
it does not prepare or execute scripts. `skill_script_prepare(name)` copies every
stored resource into a fresh temporary directory, including `SKILL.md`, both
scripts and configuration. It returns `directory` and relative `files` after
successful preparation. The agent does not choose a resource list. Execution
remains the job of a separate tool, and dependencies outside the skill are not
installed. Prepared directories remain in `sys_get_temp_dir()` after the CLI
session ends; cleanup, including partial copies after errors, belongs to the
application or environment.

Inspect the trace for `skill`, `skill_script_prepare` with name `text-stats`
preparing all four resources, then `bash` executing
the analysis script from the returned directory. The agent should inspect the
returned counts before supplying that JSON to the reporting script, and report
its successful result. The skill leaves command construction to the agent.
The skill specifies required files, UTF-8 standard input and JSON output. The
application decides how to prepare resources and supply input to its execution
tool. For this shell-based demo, `printf '%s' 'Ciao città'` preserves text without
a final newline, whereas a shell heredoc adds one. These shell details belong to
the integration, not to the skill instructions.

## 3. Two filesystems and two databases

```sh
php examples/multiple.php
```

The example configures four independent storages:

- `skills/`: `php-check` on the filesystem.
- `.agents/skills/`: the installed `caveman` skill on the filesystem.
- `skills.sqlite`: `dante`, identified by `dante`.
- `text-stats.sqlite`: `text-stats`, identified by `text-stats`.

Try **“usa la skill grill-me”** after registering a storage containing it.
The trace must load `skill` with name `grill-me`, then `skill` with name `grilling`
before interview questions. Read any further references one file per call,
copying paths from the actual loaded contents. The skill documents stay generic.

The agent-facing catalog contains only selected names and descriptions. When
names repeat, the first configured storage with a valid document wins for all
three tools. The repository retains each skill’s storage and identifier.

Try these prompts in the same conversation:

1. **“Usa php-check e spiegami i risultati.”**
2. **“Usa caveman per spiegare l'universo.”**
3. **“Use the dante skill: write a tercet about a traveller crossing a forest.”**
4. **“Usa text-stats per analizzare il testo Ciao città.”**

For `dante`, the trace should show `skill_resource` with name `dante` reading
path `references/terzina.md`. The second verse should contain `copper lantern`,
which appears only in that database reference.

## SQLite setup and local verification

### Live skill evaluations

The evaluation suite now lives in [`evaluators/`](../evaluators/README.md) and
uses Neuron's native runner. Run `composer evaluate` from the project root.
It uses `examples/.env` and makes real model API calls. Offline checks remain
available through `composer check`.

### Database setup

`sqlite-setup.php` creates two ignored local databases. It accepts optional paths:

```sh
php examples/sqlite-setup.php /tmp/dante.sqlite /tmp/text-stats.sqlite
```

The demos use the default paths under `examples/`. Every setup run deletes all
rows from the `skills` table in each database and inserts the demo data: two
resources for `dante` and four for `text-stats`. Custom rows and edits in these
tables are discarded. Each database reset and insertion runs in one transaction;
the database files and other tables are preserved.
An older demo database with a `skill_name` column needs that column renamed to
`skill_identifier`, or must be recreated before running setup.

The application owns schema creation and updates. Each database has a `skills`
table with non-null `skill_identifier`, `path` and `content` columns, and a
composite primary key on `(skill_identifier, path)` with binary collation.
`DatabaseSkillStorage` discovers skills, enumerates their resources and reads resource bytes.
The setup targets SQLite; it is not a cross-database migration framework.

Each storage reads identifiers within its own connection and table. The mixed
example uses distinct databases; equal identifiers across storages are allowed
and remain bound to their owning source.

Run the local checks without model credentials or network calls:

```sh
vendor/bin/phpunit tests/DatabaseExampleTest.php
```

They verify database reads and prepare both Python scripts and the configuration
in a temporary directory. Sequential execution covers Unicode, multiline, empty
and final-newline inputs. Changing the configuration verifies whitespace counting
in the first script and metric selection in the second. They require
`python3`. They do not verify a live model's execution choices.

Recorded live checks include failures and recovery attempts; see the
[ticket 04 live report](../docs/live-checks/name-based-skills.md).

For application integration, see the [Quick Start](../README.md#quick-start).
