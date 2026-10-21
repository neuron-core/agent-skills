# Skill evaluations

Run the current production `SkillToolkit` against a real model:

```sh
composer evaluate
```

Credentials and model come from `examples/.env` or the environment, using
`OPENAI_API_KEY` and `OPENAI_MODEL`. Environment values take precedence. The
fallback model is `gpt-5.4-nano`. The suite makes paid API requests.

## Structure

- `BaseSkillEvaluator.php`: abstract base with provider creation, session execution
  and cache dependencies.
- The six concrete `*Evaluator.php` classes: scenarios discovered in alphabetical order.
- `Assertions/`: custom assertions shared by the evaluators.
- `Fixtures/SkillFixture.php`: fixture setup and cleanup.
- `Fixtures/skills/`: skill documents and resources used by the scenarios.
- `bootstrap.php`: Composer autoloading and environment loading.

## Fixtures

The seven English skills live in `Fixtures/skills/`, as ordinary `SKILL.md`
documents with meaningful descriptions, references, configuration and scripts:

- `risk-review`: identify risks and unresolved assumptions in a technical plan.
- `rollback-plan`: describe recovery criteria, actions and verification.
- `review-my-plan`: a one-line alias that explicitly requests `risk-review`.
- `release-readiness`: combine `risk-review` and `rollback-plan`.
- `ticket-triage`: classify support requests using a priority policy.
- `csv-check`: validate orders against column and value requirements.
- `text-stats`: read a word limit from YAML, analyze text from stdin with that limit
  as an argument, then produce a PASS or FAIL report.

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
| `SkillDiscoveryEvaluator` | English requests for risk assessment, recovery procedures and ticket prioritization, each on filesystem and SQLite | Choosing and loading the relevant skill from three alternatives without its name appearing in the request. |
| `SkillLoadingEvaluator` | Three requests for `risk-review`, each on filesystem and SQLite | Exactly one `skill` call with the requested name and a successful nonempty result. |
| `SkillResourceEvaluator` | P1 outage, P2 blocked payment, P3 export workaround, each on filesystem and SQLite | Reading the policy after the skill and returning the expected priority. |
| `ScriptExecutionEvaluator` | Filesystem and SQLite; valid orders, negative amount, duplicate ID | Reading import requirements, preparing the skill, executing the validator and obtaining the expected findings. |
| `ScriptWorkflowEvaluator` | Filesystem and SQLite text statistics; one or two user turns | Reading a YAML limit, passing it as an argument, preparing Python scripts and inspecting analysis before producing a distinct report. |
| `SkillDependencyEvaluator` | Alias and two-dependency review, with explicit prompts and storage maps | Loading the parent and required dependencies, including dependencies split between filesystem and SQLite. |

There are 40 fresh conversations in a full run, all in English.
All evaluators use `chat()`. These cases do not establish a
statistical reliability rate.

The workflow evaluator uses one self-contained `text-stats` fixture, replacing
the former `sales-report` workflow. On each storage it runs two conversations:

- A request to use `text-stats` with text supplied in the same message.
- A message supplying text, followed by a request to use `text-stats` on that text.

Each of the four cases supplies a different fixed English text, with known word,
line and character counts in `expectedAnalysis`, alongside `max_words` from the
YAML configuration. `expectedReport` contains a distinct PASS or FAIL summary.
The model does not generate the input.

All fixture scripts use Python 3 and the standard library. The two text-statistics
scripts consume stdin. The agent must load the instructions, read
`config/analysis.yaml`, prepare the files, execute the analysis with `--max-words`,
inspect its JSON, then execute the report. Assertions reject missing reads or preparation, a pipeline
replacing the intermediate result, and statistics that differ from the expected output. These
cases use only synthetic fixture files and the native session runner; they do
not launch the demos or read the example databases. Passing these cases does not
establish that the CLI example's missing-preparation bug is fixed.

Loading, discovery and resource cases run every request with the expected skill on filesystem,
then on SQLite, keeping the other catalog entries unchanged.
Discovery covers three tasks, each with two English phrasings. These requests
describe the work without naming any skill.
The assertions require exactly one tool call, loading the expected skill with a
successful nonempty result. A plausible final answer or loading another skill is
not enough. Task-specific outcome checks remain in the other evaluators.

The validation cases pass ordinary orders CSV files through user requests and
check row-level errors. In the text workflow, the second script consumes the first
script's JSON through stdin. Assertions inspect script results. Prepared files
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

The loading and discovery evaluators use native `TrajectoryMatches` in strict mode
for their exact single-call sequence and `SkillWasLoaded` for the expected name
and successful result. The resource evaluator requires exactly `skill`, then
`skill_resource`; `ResourceWasRead` checks successful nonempty results and requires
the skill result before the resource is selected. The dependency evaluator checks
the exact number of skill calls and successful loads of all required skills after
the parent's instructions, allowing either sibling order. Every evaluator returns
the trajectory directly, without building an expected `steps` array or preloading
instruction texts. Script execution's six explicit dataset cases contain the CSV,
prompt template and expected validator output. The text workflow's four explicit
cases use `prompt` for either a single request or two requests in one conversation,
and check scripts against distinct `expectedAnalysis` and `expectedReport` values.

Custom assertions live in `Assertions/` and inspect native `Trajectory` records
to check selected tool behavior:

- `SkillWasLoaded`: successful activation with nonempty instructions, optionally matching an expected instruction body.
- `ResourceWasRead`: successful nonempty reference contents after the owning skill is loaded.
- `DependenciesWereLoaded`: successful nonempty instructions after the parent; siblings
  may be loaded in either order, including in the same batch.
- `ScriptsWereExecuted`: successful nonempty skill and reference reads, followed by
  preparation and successful script execution from the returned directory;
  subsequent scripts follow the previous execution's result.

Non-script cases reject script preparation and every extra tool call. Script
execution requires exactly `skill`, `skill_resource`, `skill_script_prepare`, then
`bash`; the text workflow requires exactly `skill`, `skill_resource`,
`skill_script_prepare`, then two separate `bash` calls. Repeated loads and extra calls fail. Progress messages are
allowed. Tool errors cannot replace successful results. Attempting a script before its prerequisites
are available fails even if a later retry succeeds. Provider/setup exceptions are execution
errors. Exceeding the tool execution limit is also reported by the native runner
as an execution error.

The suite measures tool use and selected task results. It does **not** judge the
quality or completeness of a risk review or rollback plan. Loading, discovery,
dependency and script execution cases check only tool calls and results. Priority
checks use text matching; the text workflow checks the analysis JSON and PASS/FAIL
report against known results for the supplied text and configured limit.
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
