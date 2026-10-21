# Skill evaluations

Run the current production `SkillToolkit` against a real model:

```sh
composer evaluate
```

Credentials and model come from `examples/.env` or the environment, using
`OPENAI_API_KEY` and `OPENAI_MODEL`. Environment values take precedence. The
fallback model is `gpt-5.4-nano`. The suite makes paid API requests.

## Structure

- `BaseSkillEvaluator.php`: abstract base with shared setup, session execution,
  reporting, cleanup and cache dependencies.
- The six concrete `*Evaluator.php` classes: scenarios discovered in alphabetical order.
- `Assertions/`: custom assertions shared by the evaluators.
- `Fixtures/SkillFixture.php`: fixture setup, artifact capture and cleanup.
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
- `sales-report`: analyze orders, then produce a report from that analysis.

These are synthetic skills written in the style of ordinary installed skills.
They contain no verification tokens, toolkit setup instructions or preparation
hints. The alias deliberately preserves the explicit "Call the Skill tool"
wording found in real router skills. No external skills or CLI packages are
installed by the suite.

Every case sees all seven skills. `Fixtures/SkillFixture.php` copies their files
to a fresh temporary filesystem and/or in-memory SQLite database. Assertions and
expected results stay outside the skill documents. Installed skills and example
databases are never changed. Temporary directories are removed after recording
the evidence, including extra preparation directories created by the model.

## Cases

| Evaluator | Variants | What it measures |
| --- | --- | --- |
| `SkillDiscoveryEvaluator` | English requests for risk assessment, recovery procedures and ticket prioritization | Choosing and loading the relevant skill from the full catalog without its name appearing in the request. |
| `SkillLoadingEvaluator` | Three requests for `risk-review` | Successful activation and a response when no plan is supplied. |
| `SkillResourceEvaluator` | P1 outage, P2 blocked payment, P3 export workaround | Reading the policy after the skill and returning the expected priority. |
| `ScriptExecutionEvaluator` | Filesystem and SQLite; valid orders, negative amount, duplicate ID | Reading import requirements, preparing the skill, executing the validator and obtaining the expected findings. |
| `ScriptWorkflowEvaluator` | Filesystem and SQLite | Reading reporting rules, analyzing paid/cancelled/refunded orders, then executing the reporting script after observing the analysis. |
| `SkillDependencyEvaluator` | Alias and two-dependency review | Loading the parent and required dependencies, including dependencies split between filesystem and SQLite. |

There are 30 fresh conversations in a full run. All requests are in English,
including a bare skill name. Each evaluator covers `chat()` and fully consumed
`stream()` calls. The three executions are different request or input variants;
they are not three identical repetitions and do not establish a statistical
reliability rate.

Discovery covers three tasks, each with different English phrasings through
`chat()` and `stream()`. These requests describe the work without naming any skill.
The assertions require the expected skill's actual instructions to be loaded;
a plausible final answer or loading another skill is not enough. This evaluator
checks routing and a nonempty response; task-specific outcome checks remain in
the other evaluators.

The script cases pass ordinary orders CSV files through user requests. Validation
checks row-level errors. Sales analysis subtracts refunds and excludes cancelled
orders; the expected total is USD 144.50. The second script consumes the first
script's generated JSON. Assertions inspect the generated analysis and Markdown
report before cleanup, as well as the source files in the prepared directory.

## Assertions and limits

Every concrete evaluator extends `BaseSkillEvaluator`, which extends Neuron's
`BaseEvaluator`. The shared `setUp()` configures the OpenAI provider from the
environment once per evaluator. The protected `runSession()` builds a fresh agent and thread
with the real OpenAI provider, `BashTool`, and production `SkillToolkit`.
The concrete classes keep explicit `getDataset`, `run` and `evaluate` methods,
including their datasets, prompts, expected results and assertions. The abstract
base is excluded from discovery.

`runSession()` fully consumes streaming responses and captures the report and artifact
evidence before cleaning up. The workflow evaluator supplies its additional file
checks through `captureArtifacts`. Completed script preparations are tracked even
when a later provider error prevents their results from entering chat history,
so their temporary directories can still be removed.

Custom assertions live in `Assertions/` and inspect native `Trajectory` records
or captured evidence:

- `SkillWasLoaded`: successful activation with the expected instruction body.
- `ResourceWasRead`: exact reference contents after the owning skill is loaded.
- `DependenciesWereLoaded`: required instruction bodies after the parent; siblings
  may be loaded in either order, including in the same batch.
- `NoRepeatedSkillLoads`: reject another successful load of the same skill during
  one user turn, even when the dependency is eventually loaded. Failed loads may
  be retried; a later user turn starts a new scope.
- `ScriptsWereExecuted`: required references and preparation before successful
  script execution; subsequent scripts follow the previous execution's result.
- `EvidenceIsValid`: complete preparation, expected generated files and completion
  without exceeding the tool execution limit.

Non-script cases also reject script preparation. Independent extra calls and
progress messages are allowed. Recovered tool errors are recorded and do not by
themselves invalidate completed work. Provider/setup exceptions are execution
errors, not model behavior failures.

The suite measures tool use and selected task results. It does **not** judge the
quality or completeness of a risk review or rollback plan: those cases check
routing and a nonempty final response. Priority and final sales-total checks use
text matching; script results and generated artifacts are checked separately.
This does not establish performance on arbitrary skills, large catalogs,
conversation histories, or every natural-language paraphrase.

## Reports and offline checks

The native runner groups results by evaluator and writes `evaluation-results.json`.
The report includes serialized messages, readable transcripts, expected results,
actual production guidelines, storage, model, usage, and captured artifact evidence.
Provider exception messages are reduced to their exception class and provider code;
credentials are not included. Reports and evaluation caches are ignored by Git.

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
interface and verify chat, stream consumption, artifact capture, conversation
isolation, error reporting and cleanup. Production evaluations use the provider
configured by the inherited `setUp()`.
Regression checks reject repeated successful alias loads and missing dependencies,
including the case where a plausible final response hides incomplete tool use.
