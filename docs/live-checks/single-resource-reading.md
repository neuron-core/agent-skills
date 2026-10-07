# Single-resource reading: production validation

The selected prototype used `skill(name)`, `skill_resource(name, path)` returning
one file's complete contents as text, and the existing preparation tool.
Its operational guidelines and skill description were ported to production.
The user approved that candidate after its 39/39 recorded prototype trials.

## Production checks

Thirty fresh conversations used gpt-5.4-nano, the production SkillToolkit and the
same mixed filesystem/SQLite catalog as the prototype. Five prompt formulations
per case were each used twice. No fake provider or prototype tool was substituted.

| Required behaviour before the response | Clean successes |
| --- | --- |
| Load grill-me and then grilling | 10/10 |
| Load dante, read its database reference and use copper lantern | 10/10 |
| Read a filesystem guide, then its literal nested Unicode reference | 10/10 |

Clean success required actual successful tool results before any response, no
invented file paths, no tool errors, no document rereads and no bash searches.
The filesystem fixture's nested path contains spaces, a literal %20 and città;
its configured values CITTÀ-47 and AMBER must appear unchanged in the answer.
All production input schemas match those of the selected candidate. Guidelines
match too; the first production records differed only in a final newline, which
was restored during the port. Their instruction text and behaviour were identical.

The automated suite additionally passes 280 tests / 934 assertions and PHPStan.
It verifies raw text, Unicode, CRLF and quotation marks, empty text, missing and
invalid resources, source precedence and isolation, and existing preparation and
script execution. FakeAIProvider checks establish that guidelines and definitions
reach the provider; they do not establish spontaneous model obedience.

## Original interactive example

Using `php examples/multiple.php`, the same conversation included:

```text
ciao
usa la skill grill-me
Usa la skill dante per scrivere una terzina su un viaggiatore in una foresta.
```

Observed calls for the wrapper:

```text
skill {"name":"grill-me"}
skill {"name":"grilling"}
```

Interview questions followed only after both documents were loaded. The subsequent
poetry request in that same conversation produced verses without loading dante
or its reference. This cross-task conversation did not satisfy the resource
procedure, despite the ten successful fresh-conversation database checks. The shared chat runner,
execution tool and skill documents were unchanged.

A separate fresh run of examples/multiple.php with the same poetry request
loaded dante, read references/terzina.md and used copper lantern. This confirms
the observed difference between a fresh request and the cross-task conversation.

## Evidence and limits

Local complete traces are under `.scratch/skill-guidelines-loop/results/production/`;
the experiment report and historical failed variants remain in that workspace.
The original-example trace is captured in the same experiment directory.
Earlier variants that passed one sample failed a later confirmation; those
failures informed the selected combination of guidelines, tool description,
single-file reading and direct text output.

The 30/30 production outcomes are observations for the recorded model and cases,
not a guarantee for every future model response. No automatic dependency parser,
forced model-response loop or script execution was introduced by the reading tool.
