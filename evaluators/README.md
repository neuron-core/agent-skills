# Skill evaluations

Run the current production `SkillToolkit` against a real model:

```sh
composer evaluate
```

Credentials and model come from `examples/.env` or the environment, using
`OPENAI_API_KEY` and `OPENAI_MODEL`. Environment values take precedence. The
fallback model is `gpt-5.4-nano`. The suite makes paid API requests.

Set `OPENAI_REASONING_EFFORT` to compare reasoning settings. When unset or empty,
the provider uses the model's default. `OPENAI_API=responses` selects Neuron's
Responses provider; the default remains Chat Completions. Nano and mini require
Responses for function tools with `low` or higher. Run the same suite with `low`:

```sh
OPENAI_API=responses OPENAI_MODEL=gpt-5.4-nano OPENAI_REASONING_EFFORT=low composer evaluate
OPENAI_API=responses OPENAI_MODEL=gpt-5.4-mini OPENAI_REASONING_EFFORT=low composer evaluate
```

These comparisons also change APIs: Responses can normalize function schemas
into strict mode, while Chat Completions defaults to non-strict function calling
([OpenAI documentation](https://developers.openai.com/api/docs/guides/function-calling#strict-mode)).

Every model and reasoning setting uses the same datasets and assertions.

## Structure

- `BaseSkillEvaluator.php`: abstract base with provider creation, session execution
  and cache dependencies.
- The six concrete `*Evaluator.php` classes: scenarios discovered in alphabetical order.
- `Assertions/`: custom assertions shared by the evaluators.
- `Fixtures/SkillFixture.php`: fixture setup and cleanup.
- `Fixtures/skills/`: skill documents and resources used by the scenarios.
- `bootstrap.php`: Composer autoloading and environment loading.

## Fixtures

The six English skills live in `Fixtures/skills/`, as ordinary `SKILL.md`
documents with meaningful descriptions, references, configuration and scripts:

- `risk-review`: identify risks and unresolved assumptions in a technical plan.
- `rollback-plan`: describe recovery criteria, actions and verification.
- `review-my-plan`: a one-line alias that explicitly requests `risk-review`.
- `release-readiness`: combine `risk-review` and `rollback-plan`.
- `ticket-triage`: classify support requests using a priority policy.
- `csv-check`: validate orders against column and value requirements.

These are synthetic skills written in the style of ordinary installed skills.
They contain no verification tokens, toolkit setup instructions or preparation
hints. The alias deliberately preserves the explicit "Call the Skill tool"
wording found in real router skills. No external skills or CLI packages are
installed by the suite.

Each dataset item declares a small catalog and the storage for each skill:

```php
'skills' => [
    'risk-review' => 'filesystem',
    'rollback-plan' => 'database',
    'release-readiness' => 'filesystem',
],
```

`SkillFixture` stores this configuration in its constructor. Its explicit `setUp()`
loads only the selected skills, using separate filesystem and database setup methods.
It creates a temporary skill directory only when the map includes filesystem skills;
a database-only fixture uses in-memory SQLite without a directory. Evaluators use
`createFile()` for temporary inputs; the fixture removes them during `cleanUp()`.
Assertions and expected results stay outside the skill documents.
Installed skills and example databases are never changed. Cleanup removes the
filesystem fixture and directories created by script preparation.

## Cases

| Evaluator | Variants | What it measures |
| --- | --- | --- |
| `SkillDiscoveryEvaluator` | English requests for risk assessment and recovery procedures, each on filesystem and SQLite | Choosing and loading the relevant skill from three alternatives without its name appearing in the request; exactly one `skill` call. |
| `SkillDiscoveryWithResourceEvaluator` | Two English requests for ticket prioritization, each on filesystem and SQLite | Discovering `ticket-triage`, then reading its required policy; exactly `skill`, then `skill_resource`. |
| `SkillLoadingEvaluator` | Three requests for `risk-review`, each on filesystem and SQLite | Exactly one `skill` call with the requested name and a successful nonempty result. |
| `SkillResourceEvaluator` | P1 outage, P2 blocked payment, P3 export workaround, each on filesystem and SQLite | Reading the policy after the skill and returning the expected priority. |
| `ScriptExecutionEvaluator` | Filesystem and SQLite; valid orders, negative amount, duplicate ID | Reading import requirements, preparing the skill, executing the validator and obtaining the expected findings. |
| `SkillDependencyEvaluator` | Alias and two-dependency review, with explicit prompts and storage maps | Loading the parent and required dependencies, including dependencies split between filesystem and SQLite. |

There are 36 fresh conversations in a full run, all in English.
All evaluators use `chat()`. These cases do not establish a
statistical reliability rate.

All fixture scripts use Python 3 and the standard library. Script execution is
covered by `csv-check`: load the instructions, read `references/order-format.md`,
prepare the files, then execute the validator against the supplied CSV file.
The six cases cover valid orders, a negative amount and a duplicate order ID on
both filesystem and SQLite storage. Assertions check the expected row-level
findings. These cases use synthetic fixture files; they do not launch demos or
read example databases.

Loading, discovery and resource cases run every request with the expected skill on filesystem,
then on SQLite, keeping the other catalog entries unchanged.
Discovery covers three tasks, each with two English phrasings. These requests
describe the work without naming any skill.
The eight risk and recovery cases require exactly one tool call, loading the
expected skill with a successful nonempty result. The four ticket cases live in
`SkillDiscoveryWithResourceEvaluator` and require exactly `skill`, then
`skill_resource`, successfully reading `references/priorities.md` after the skill's
instructions return. Both evaluators reject extra calls and use the same checks
for every item in their dataset. Task-specific outcome checks remain in the
dedicated evaluators.

The validation cases pass ordinary orders CSV files through user requests and
check row-level errors. Assertions inspect the validator result. Prepared files
are cleaned up after the conversation.

## Assertions and limits

Every concrete evaluator extends `BaseSkillEvaluator`, which extends Neuron's
`BaseEvaluator`. The shared `provider()` builds the OpenAI provider from the
environment when a conversation runs. The protected `runSession()` builds a fresh agent and thread
with the real OpenAI provider, `BashTool`, and production `SkillToolkit`.
The concrete classes keep explicit `getDataset`, `run` and `evaluate` methods,
including their datasets, prompts, expected results and assertions. The abstract
base is excluded from discovery.

`runSession()` accepts a repository and user turns, calls `chat()` and returns the
native `Trajectory`. It does not own the fixture or build report metadata. Each
caller cleans its fixture in `finally`.
An optional tool-call observer lets fixtures record script preparations for cleanup, including
results that a later provider error prevents from entering chat history.

The loading and both discovery evaluators use native `TrajectoryMatches` in strict
mode for their exact sequence and `SkillWasLoaded` for the expected name and
successful result. Discovery with a resource also uses `ResourceWasRead` for the
required policy. The resource evaluator requires exactly `skill`, then
`skill_resource`; `ResourceWasRead` checks successful nonempty results and requires
the skill result before the resource is selected. The dependency evaluator checks
the exact number of skill calls and successful loads of all required skills after
the parent's instructions, allowing either sibling order. Every evaluator returns
the trajectory directly, without building an expected `steps` array or preloading
instruction texts. Script execution's six explicit dataset cases contain the CSV,
prompt template and expected validator output.

Custom assertions live in `Assertions/` and inspect native `Trajectory` records
to check selected tool behavior:

- `SkillWasLoaded`: successful activation with nonempty instructions, optionally matching an expected instruction body.
- `ResourceWasRead`: successful nonempty reference contents after the owning skill is loaded.
- `DependenciesWereLoaded`: successful nonempty instructions after the parent; siblings
  may be loaded in either order, including in the same batch.
- `ScriptWasExecuted`: exactly one successful skill load, reference read and
  preparation, followed by one or more attempts of the required script. Each call
  follows the previous result. The final attempt must exit successfully, use the
  prepared directory and return the expected validator output. Its score is
  `1 / attempts`: 1 means no retries, while lower passing scores indicate recovery.
  Attempts remain visible in the trajectory and the assertion's result context.

Non-script cases reject script preparation and every extra tool call. Script
execution allows consecutive retries of the validator after the required reads
and preparation. Repeated loads, missing prerequisites, batched calls, unrelated
commands and an unsuccessful final attempt fail. Progress messages are allowed.
Provider/setup exceptions and exceeding the tool execution limit are execution
errors reported by the native runner.

The suite measures tool use and selected task results. It does **not** judge the
quality or completeness of a risk review or rollback plan. Loading, discovery,
dependency and script execution cases check only tool calls and results. Priority
checks use text matching; script execution checks the validator output against
the expected findings for the supplied CSV.
Prepared file contents are covered by tool tests rather than model evaluations.
This does not establish performance on arbitrary skills, large catalogs,
conversation histories, or every natural-language paraphrase.

## Reports and offline checks

The native runner groups results by evaluator and writes `evaluation-results.json`.
It reports case inputs, outputs and assertion failures. Every evaluator returns a
native trajectory.
There is no shared report payload for model,
storages, guidelines or usage. Provider exception messages are reduced to their
exception class and provider code. Reports and evaluation caches are ignored by Git.

Caching is off by default. Cached conversations are not new model executions.
The base declares itself, production sources, bootstrap and fixture
files in the inherited `cacheDependencies()`. Changes to those sources invalidate cached runs;
assertion changes reuse recorded conversations and evaluate them again. Use
`--fresh` when changing environment settings such as the model.

```sh
composer check
```

PHPUnit verifies the assertions with recorded messages and exercises the real
fixture tools and scripts on both storage backends without contacting a model.
Execution tests use a concrete test evaluator with Neuron's `FakeAIProvider`
through its existing provider
interface and verify chat, conversation isolation, returned trajectories,
tool execution, error reporting and cleanup. Production evaluations use the provider
created by the inherited `provider()`.
Regression checks reject repeated successful alias loads and missing dependencies,
including the case where a plausible final response hides incomplete tool use.
