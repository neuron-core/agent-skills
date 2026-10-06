# Try the skills demo

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

This example shows an actual conversation; wording, paths and results can vary on your machine.

```text
$ php agent-loop.php
Available skills: php-check, caveman

Try php-check for runtime checks or caveman for terse answers.
See examples/README.md for setup and both scenarios.

Type a message, or 'exit' to quit.

> use caveman skill to explain the universe

[tool: skill {"name":"caveman"}]

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

[tool: skill {"name":"php-check"}]

[tool: skill_resource {"name":"php-check","path":"references/checks.md"}]

[tool: bash {"command":"php scripts/check.php","working_directory":"/home/asterix/neuron-core/agent-skills/examples/skills/php-check"}]

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
executes the bundled script. Its output contains `PHP_VERSION`, `CURL_EXTENSION`,
`JSON_EXTENSION` and `PROC_OPEN`; the agent explains any failed checks.

Type `exit` or `quit` to stop. Restart the script after installing new skills.
For integration into your application, see the [Quick Start](../README.md#quick-start).
