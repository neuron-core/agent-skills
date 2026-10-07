# Ticket 04 live checks

Run on 2026-10-07 using the existing `examples/demo-chat.php`, the configured
OpenAI model `gpt-5.4-nano` and unchanged BashTool. PHP CLI: 8.5.11. These were real provider
calls, separate from FakeAIProvider integration tests. Fixtures and raw logs
were created under `/tmp/skill-ticket04-live/`; credentials are not recorded.

## Skill referenced by name

A filesystem fixture declared `runtime-guide` with this complete instruction:
“Use the php-check skill to inspect the runtime before explaining its compatibility.”
The toolkit registered this fixture and `examples/skills`, using the existing
runner and tool-registration flow. Prompt: “Usa runtime-guide per spiegarmi la
compatibilità PHP.”

The model first loaded runtime-guide, then invented resource paths and attempted
three unsuccessful batch reads. It recovered by loading php-check by name,
reading its actual reference, preparing it and executing the local script.
The final answer reported PHP 8.5.11, curl/json loaded and proc_open available.

## Local script

Ran `examples/filesystem.php` with “Usa php-check e spiegami i risultati.”
The model loaded the document, read references/checks.md, prepared php-check and
executed `php scripts/check.php` in the original directory. Bash reported success;
the final response reported all four checks passing. No recovery was needed.

## Database scripts and configuration

Created fresh SQLite fixtures with `examples/sqlite-setup.php` using explicit
paths under the temporary fixture directory. Registered only the statistics
database through the same toolkit and chat runner.

First prompt: “Usa text-stats per analizzare il testo esatto Ciao città, senza
newline finale.” The model misinterpreted the target text, skipped the required
configuration read and supplied an unsupported `stdin` field to BashTool.
The first script received empty input; the second invocation failed. A pipeline
retry still used the unsupported field and returned zeros. This run failed to
preserve input and did not complete the requested workflow correctly.

A fresh conversation used explicit text delimiters. It read configuration and
both scripts, prepared all resources, then tried a heredoc that added a newline
and invoked report.py without its JSON input (Bash reported error). It recovered
with `echo -n 'Ciao città' | python3 scripts/analyze.py | python3 scripts/report.py`
in the prepared directory, reporting 2 words, 1 line and 10 characters.

An explicit follow-up explained that BashTool supports only `command` and
`working_directory`, asked it to transport stdin through the command and to use
the actual analyze.py JSON. It read configuration again, ran analyze.py with
`echo -n` and ran the pipeline successfully, again reporting 2/1/10. This is an
integration recovery instruction, not an alteration to the generic skill.

Preparation succeeded in all database attempts; failures concerned model input
selection and the separate execution tool. No skill document, chat runner or
execution tool was modified to make these checks pass. These observations do
not guarantee future model obedience; deterministic process tests independently
verify exact-input transport and sequential script behavior.

## Actual traces

Below are the runner's recorded tool calls, Bash statuses and final responses.
The runner intentionally does not render other tool-result bodies.

### by-name

```text
See examples/README.md for setup and scenarios.

Type a message, or 'exit' to quit.

>
[tool: skill {"name":"runtime-guide"}]

[tool: skill_resources {"name":"runtime-guide","paths":["references/compatibility-overview.md","references/matrix.md","references/troubleshooting.md"]}]

[tool: skill_resources {"name":"runtime-guide","paths":["references/compatibility-overview.md","references/matrix.md","references/troubleshooting.md"]}]

[tool: skill_resources {"name":"runtime-guide","paths":["matrix.md","troubleshooting.md","compatibility-overview.md"]}]

[tool: skill {"name":"php-check"}]

[tool: skill_resources {"name":"php-check","paths":["references/checks.md"]}]

[tool: skill_script_prepare {"name":"php-check"}]

[tool: bash {"command":"php scripts/check.php","working_directory":"/home/asterix/neuron-core/agent-skills/examples/skills/php-check"}]

tool: bash success

Certo! Ho usato **runtime-guide** (tramite lo skill **php-check**) per verificare la compatibilità del runtime PHP disponibile qui.

### Risultato compatibilità (PHP CLI)
- **PHP 8.1+**: ✅ `PHP_VERSION=8.5.11`
- **estensione cURL**: ✅ `CURL_EXTENSION=loaded`
- **estensione JSON**: ✅ `JSON_EXTENSION=loaded`
- **proc_open disponibile**: ✅ `PROC_OPEN=available`

Quindi, **questo ambiente PHP soddisfa i requisiti base** richiesti per eseguire applicazioni/strumenti che dipendono da questi componenti.

### Nota rapida
Questi controlli **non verificano** la tua configurazione applicativa, accesso di rete, né che le chiamate a servizi esterni funzionino: verificano solo le capacità del runtime PHP usato per eseguire i comandi.

>
```

### local

```text
See examples/README.md for setup and scenarios.

Type a message, or 'exit' to quit.

>
[tool: skill {"name":"php-check"}]

[tool: skill_resources {"name":"php-check","paths":["references/checks.md"]}]

[tool: skill_script_prepare {"name":"php-check"}]

[tool: bash {"command":"php scripts/check.php","working_directory":"/home/asterix/neuron-core/agent-skills/examples/skills/php-check"}]

tool: bash success

Ecco i risultati di **php-check** (eseguito su questo ambiente):

- **PHP_VERSION=8.5.11** → ✅ **Passa** (richiesto PHP **8.1+**)
- **CURL_EXTENSION=loaded** → ✅ **Passa** (estensione cURL disponibile)
- **JSON_EXTENSION=loaded** → ✅ **Passa** (estensione JSON disponibile)
- **PROC_OPEN=available** → ✅ **Passa** (proc_open disponibile per eseguire comandi esterni)

**Conclusione:** tutti i requisiti del check risultano soddisfatti.

>
```

### database

```text
See examples/README.md for setup and scenarios.

Type a message, or 'exit' to quit.

>
[tool: skill {"name":"text-stats"}]

[tool: skill_script_prepare {"name":"text-stats"}]

[tool: bash {"command":"python3 scripts/analyze.py","working_directory":"/tmp/skill-script-28ebe2450812bdbbc600fbc967c80ea1","stdin":"Ciao città, senza newline finale."}]

tool: bash success

[tool: bash {"command":"python3 scripts/report.py","working_directory":"/tmp/skill-script-28ebe2450812bdbbc600fbc967c80ea1","stdin":"{\"words\": 0, \"lines\": 0, \"characters\": 0}\n"}]

tool: bash error

[tool: bash {"command":"python3 scripts/analyze.py | python3 scripts/report.py","working_directory":"/tmp/skill-script-28ebe2450812bdbbc600fbc967c80ea1","stdin":"Ciao città, senza newline finale."}]

tool: bash success

Ho analizzato il testo **esatto**: `Ciao città, senza newline finale.`

Risultati (text-stats):
```json
{"words": 0, "lines": 0, "characters": 0}
```

>
```

### database-recovery

```text
See examples/README.md for setup and scenarios.

Type a message, or 'exit' to quit.

>
[tool: skill {"name":"text-stats"}]

[tool: skill_resources {"name":"text-stats","paths":["config/statistics.json","scripts/analyze.py","scripts/report.py"]}]

[tool: skill_script_prepare {"name":"text-stats"}]

[tool: bash {"command":"python3 /tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763/scripts/analyze.py <<'EOF'\nCiao città\nEOF","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash success

[tool: bash {"command":"python3 /tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763/scripts/report.py","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash error

[tool: bash {"command":"echo -n 'Ciao città' | python3 scripts/analyze.py | python3 scripts/report.py","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash success

**Testo:** “Ciao città”

- **parole:** 2
- **righe:** 1
- **caratteri:** 10

>
[tool: bash {"command":"cat config/statistics.json","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash success

[tool: bash {"command":"echo -n 'Ciao città' | python3 scripts/analyze.py","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash success

[tool: bash {"command":"echo -n 'Ciao città' | python3 scripts/analyze.py | python3 scripts/report.py","working_directory":"/tmp/skill-script-3e0f9475e6dfb893a35891d48e3f8763"}]

tool: bash success

Configurazione letta da `config/statistics.json`:
- `include_whitespace: true`
- `metrics: ["words", "lines", "characters"]`

Risultato per **“Ciao città”** (senza newline):

- **words:** 2
- **lines:** 1
- **characters:** 10

>
```
