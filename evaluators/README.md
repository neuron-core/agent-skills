# Skill evaluations

This suite checks whether a real model uses the production `SkillToolkit`
correctly: it picks the right skill, reads its references, loads the skills it
depends on and runs its scripts. Use it when you change the toolkit, its tool
descriptions or its guidelines, or when you compare models and reasoning
settings. Every case runs on both filesystem and SQLite storage.

The suite calls the OpenAI API, so every run makes **paid requests**.

## What it measures

A full run has 36 conversations, all in English, across six evaluators:

| Evaluator | Cases | What passes |
| --- | --- | --- |
| `SkillLoadingEvaluator` | 6 requests that name `risk-review` | Exactly one `skill` call for the requested skill, with a successful, nonempty result. |
| `SkillDiscoveryEvaluator` | 8 requests for a risk assessment or a recovery procedure that don't name the skill | The model picks the right skill from three alternatives, with exactly one `skill` call. |
| `SkillDiscoveryWithResourceEvaluator` | 4 ticket prioritization requests | `skill` for `ticket-triage`, then `skill_resource` reading `references/priorities.md`, and nothing else. |
| `SkillResourceEvaluator` | 6 tickets (P1 outage, P2 blocked payment, P3 export workaround) | The model reads the policy after loading the skill and answers with the expected priority. |
| `SkillDependencyEvaluator` | 6 cases with an alias and a two-dependency review | The parent skill loads first, then every required dependency, in any order. Some cases split dependencies between filesystem and SQLite. |
| `ScriptExecutionEvaluator` | 6 order CSVs (valid, negative amount, duplicate ID) | The model reads the format reference, prepares the skill, runs the validator and gets the expected findings. Retries are allowed, but the score drops to `1 / attempts`. |

All cases except script execution fail if the model makes any extra tool call
or prepares scripts it doesn't need. Repeated loads, missing prerequisites,
calls batched together that should run one after another, and a claimed answer
without the required tool calls also fail.

### Limits

The suite measures tool use and a few specific results. It does **not** judge
the quality of a risk review or rollback plan. Priorities are checked by text
matching. The cases show regressions, not a statistical reliability rate. They
don't cover arbitrary skills, large catalogs, long conversation histories, or
every possible phrasing.

## Getting started

You need the project's dev dependencies (`composer install`) and Python 3,
which the fixture scripts use.

1. Copy `examples/.env.example` to `examples/.env` and set `OPENAI_API_KEY`.
   Values in the shell environment take precedence over the file.
2. Run the suite from the repository root:

   ```sh
   composer evaluate
   ```

The console shows results grouped by evaluator, with each case's inputs,
outputs and assertion failures. The same report is written to
`evaluation-results.json`, which Git ignores.

## Compare models and reasoning settings

These environment variables choose the model:

- `OPENAI_MODEL` defaults to `gpt-5.4-mini`.
- `OPENAI_REASONING_EFFORT` is unset by default, which uses the model's own
  default.
- `OPENAI_API=responses` switches from Chat Completions to Neuron's Responses
  provider.

Nano and mini models need the Responses API to use function tools with `low`
or higher reasoning effort:

```sh
OPENAI_API=responses OPENAI_MODEL=gpt-5.4-nano OPENAI_REASONING_EFFORT=low composer evaluate
OPENAI_API=responses OPENAI_MODEL=gpt-5.4-mini OPENAI_REASONING_EFFORT=low composer evaluate
```

Switching API also changes function calling: Responses can turn function
schemas into strict mode, while Chat Completions is non-strict by default
([OpenAI documentation](https://developers.openai.com/api/docs/guides/function-calling#strict-mode)).
Every setting uses the same datasets and assertions.

## Iterate on assertions without paying again

Caching is off by default. With `--cache`, conversations whose sources haven't
changed are served from `.neuron/cache/evaluation/`, and the assertions run
again against them:

```sh
composer evaluate -- --cache
```

Changes to `src/`, the evaluator base class, the bootstrap or the fixtures
invalidate the cache. Changes to assertions don't. A cached conversation is not
a new model run, so use `--fresh` after you change the model or other
environment settings:

```sh
composer evaluate -- --fresh
```

## Fixture skills

The cases use six synthetic skills in `Fixtures/skills/`. They are ordinary
`SKILL.md` documents with references, configuration and scripts:

- `risk-review`: identifies risks and unresolved assumptions in a technical plan.
- `rollback-plan`: describes recovery criteria, actions and verification.
- `review-my-plan`: a one-line alias that tells the model to call the Skill tool
  with `risk-review`, as real router skills do.
- `release-readiness`: combines `risk-review` and `rollback-plan`.
- `ticket-triage`: classifies support requests using a priority policy.
- `csv-check`: validates orders against column and value requirements, using a
  Python script that only needs the standard library.

The skills contain no verification tokens or toolkit hints. Expected results
stay in the evaluators. The suite never installs external skills and never
changes installed skills or the example databases.

Each dataset item lists the skills in its catalog and where each one is stored:

```php
'skills' => [
    'risk-review' => 'filesystem',
    'rollback-plan' => 'database',
    'release-readiness' => 'filesystem',
],
```

`SkillFixture` loads only these skills. Filesystem skills go into a temporary
directory, and database skills go into in-memory SQLite. Cleanup removes that
directory, the temporary input files and the directories created by script
preparation.

## Add or change a case

- Each `*Evaluator.php` holds its own dataset, prompts, expected results and
  assertions. The runner discovers evaluators in alphabetical order.
- `BaseSkillEvaluator` builds the OpenAI provider and runs each item in a fresh
  agent and thread, with `BashTool` and the production `SkillToolkit`. It isn't
  discovered as an evaluator.
- `Assertions/` holds the custom assertions. They inspect the trajectory and
  check how a tool was used. `SkillWasLoaded`, `ResourceWasRead` and
  `DependenciesWereLoaded` check successful, nonempty results in the right
  order. `ScriptWasExecuted` checks the load, read, preparation and validator
  run sequence. Exact sequences use Neuron's `TrajectoryMatches` in strict mode.

`composer test` checks the custom assertions against recorded messages,
without calling a model.
