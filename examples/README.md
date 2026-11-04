# Skills demos

Interactive chats that show an agent discovering skills, reading their
resources and running their scripts from different storages. Each demo prints
the tool calls, so you can follow what the agent does.

| Demo | Storages | Skills |
| --- | --- | --- |
| `filesystem.php` | `skills/` and `.agents/skills/` | `php-check`, `caveman`, `grill-me`, `grilling` |
| `database.php` | `text-stats.sqlite` | `text-stats` |
| `multiple.php` | Both directories and two SQLite databases | All of the above, plus `dante` |

All demos share the chat runner in `bootstrap.php` and make real OpenAI API
requests.

## Setup

You need PHP 8.1+ with `pdo_sqlite`, Composer, Node.js (for the Skills CLI),
Python 3 (for `text-stats`) and an OpenAI API key.

1. From the repository root, install the dependencies. The demos use the
   repository's `vendor/`, including dev dependencies, so don't pass `--no-dev`:

   ```sh
   composer install
   cd examples
   ```

   Run every following command from `examples/`.

2. Create the demo databases, `skills.sqlite` with `dante` and
   `text-stats.sqlite` with `text-stats`. Running it again resets them:

   ```sh
   php sqlite-setup.php
   ```

3. Create the configuration file, then set `OPENAI_API_KEY` in `.env`:

   ```sh
   cp .env.example .env
   ```

4. Install the community skills into `.agents/skills`, used by the filesystem
   and multiple-storage demos:

   ```sh
   npx skills add juliusbrussee/caveman --skill caveman --agent universal --yes
   npx skills add mattpocock/skills --skill grill-me grilling --agent universal --yes
   ```

Type `exit` to stop a demo. Restart it after installing new skills.

## Filesystem: run a local script

```sh
php filesystem.php
```

Ask: **“Use php-check and explain the results.”**

```text
[tool: skill {"name":"php-check"}]
[tool: skill_resource {"name":"php-check","path":"references/checks.md"}]
[tool: skill_script_prepare {"name":"php-check"}]
[tool: bash {...}]
tool: bash success
```

The agent loads the instructions, reads the referenced requirements, then runs
`php scripts/check.php` from the skill's own directory. The answer reports PHP
version, `curl`, `json` and `proc_open`.

## Database: run scripts stored in SQLite

```sh
php database.php
```

Ask: **“Use text-stats to analyse the text Ciao città.”**

The skill is stored entirely in SQLite: `SKILL.md`, a configuration file and two
Python scripts. The agent:

1. reads `config/analysis.yaml` with `skill_resource` to get the word limit;
2. calls `skill_script_prepare`, which copies all four files to a temporary
   directory;
3. runs `scripts/analyze.py`, which returns
   `{"words": 2, "lines": 1, "characters": 10, "max_words": 12}`;
4. passes that JSON to `scripts/report.py`, which returns
   `PASS: 2 words, limit 12.`

Prepared copies stay in `sys_get_temp_dir()` after the demo ends.

## Multiple storages: one catalog

```sh
php multiple.php
```

Try these prompts in the same conversation:

1. **“Use php-check and explain the results.”**
2. **“Use caveman to explain the universe.”**
3. **“Use the dante skill: write a tercet about a traveller crossing a forest.”**
4. **“Use text-stats to analyse the text Ciao città.”**
5. **“Use grill-me to stress-test my plan to rewrite our API in Go.”**

The agent sees one catalog and doesn't need to know where each skill is stored.
For `dante`, it reads `references/terzina.md` from the database: the second line
of the tercet should contain `copper lantern`, which appears only in that file.

`grill-me` only tells the agent to use `grilling`, so the agent loads both before
asking its first questions:

```text
[tool: skill {"name":"grill-me"}]
[tool: skill {"name":"grilling"}]
```

## Checks without an API key

```sh
../vendor/bin/phpunit -c .. ../tests/DatabaseExampleTest.php
```

Runs the `text-stats` scripts from the demo database without calling a model.
Requires `python3`. To evaluate live agent behaviour, see
[`evaluators/`](../evaluators/README.md).
