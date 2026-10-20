# Caricamento delle skill: Codex, OpenCode e Pi

Ricerca del 20 ottobre 2026. Domanda: quando una skill A dice di usare una
skill B, gli altri agenti caricano B automaticamente oppure affidano il passaggio
al modello?

## Risultato

**Nelle fonti e nei percorsi di caricamento esaminati non emerge un risolutore
automatico delle dipendenze fra skill.** OpenCode e Pi mostrano al modello un
catalogo e gli chiedono di caricare le istruzioni pertinenti. Il loro codice
carica una skill selezionata alla volta. Codex documenta lo stesso principio di
caricamento progressivo; la documentazione consultata non descrive dipendenze
fra skill. Sono conclusioni limitate alle fonti indicate sotto, non una prova
dell'assenza di ogni possibile estensione.

| Agente | Scelta implicita | Invocazione esplicita | Passaggio A → B |
|---|---|---|---|
| Codex | Il modello sceglie dalla descrizione | `$skill`, selettore `/skills` | Nessun automatismo documentato nelle fonti consultate |
| OpenCode v2 | Il modello chiama `skill({id})` | Menzione `@skill-id` | Il percorso standard esaminato restituisce solo A |
| Pi | Il modello legge `SKILL.md` con un tool di lettura | `/skill:nome` espanso dal programma | L'espansione esaminata inserisce solo A |

Fonti del confronto: [Codex: Build skills](https://learn.chatgpt.com/docs/build-skills),
[OpenCode v2: Skills](https://opencode.ai/v2/docs/skills),
[Pi: documentazione delle skill](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/docs/skills.md).

## Codex

La documentazione ufficiale distingue attivazione esplicita e implicita. Il
contesto iniziale contiene nome, descrizione e percorso; il testo completo viene
caricato quando la skill è scelta. L'utente può indicare `$skill` o usare il
selettore `/skills`; altrimenti la scelta dipende dalla descrizione. In
`agents/openai.yaml`, `allow_implicit_invocation: false` disabilita la selezione
implicita mantenendo quella esplicita. [Build skills](https://learn.chatgpt.com/docs/build-skills).

Il campo `dependencies.tools` riguarda strumenti, per esempio server MCP:
**non dichiara che una skill debba caricarne un'altra**. La documentazione degli
errori di pubblicazione specifica che, per `dependencies`, è supportata soltanto
la chiave `tools`. [Build skills](https://learn.chatgpt.com/docs/build-skills),
[Plugin submission errors](https://developers.openai.com/plugins/deploy/submission-errors).

È stato inoltre letto il codice del prompt e dei frammenti di contesto al
commit `464add49587a7cb6032d5ab04faa8a1585947853`, descritto nella sezione
dedicata alle istruzioni effettive. La verifica non costituisce una revisione
completa dei percorsi di caricamento o delle singole interfacce.

## OpenCode

È stato esaminato il ramo `dev` al commit
[`d8148796dc74a342c96c7b4febf03dc664e1058c`](https://github.com/anomalyco/opencode/commit/d8148796dc74a342c96c7b4febf03dc664e1058c).
Il confronto implementativo riguarda v2: la documentazione v1 usa ancora
`skill({name})`, mentre v2 usa `skill({id})`.
[Documentazione v1](https://opencode.ai/docs/skills),
[documentazione v2](https://opencode.ai/v2/docs/skills).

Il prompt generato presenta ID, nome e descrizione delle skill accessibili e
istruisce il modello a usare il tool quando il compito corrisponde alla
descrizione. Non contiene, in questo punto, una regola speciale che risolva
catene fra skill.
[Generazione delle istruzioni](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/skill/instructions.ts#L25-L35).

Il tool cerca la skill richiesta, verifica il permesso e chiama `Skill.prepare`
su quella singola skill. `prepare` restituisce il corpo in un blocco
`<skill_content>`, la directory e fino a dieci percorsi di file di supporto;
non legge automaticamente il loro contenuto né carica altre skill.
[Esecuzione del tool](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/tool/plugin/skill.ts#L46-L65),
[preparazione e formato del risultato](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/skill.ts#L36-L74).

Il parser esaminato ricava nome, descrizione, controllo di attivazione, percorso
e contenuto. Interpreta `metadata.opencode/autoinvoke` e
`disable-model-invocation`; non conserva un elenco di dipendenze fra skill.
Questo limita l'affermazione al parser standard, senza escludere plugin custom.
[Parser del frontmatter](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/config/plugin/skill-file.ts#L9-L63).

**Inferenza:** se il corpo di A ordina di caricare B, nel percorso standard
esaminato il modello deve effettuare una seconda chiamata. Il tool non esegue
l'ordine al suo posto. Le fonti sono l'esecuzione e la preparazione collegate sopra.

## Pi

Il repository storico `badlogic/pi-mono` reindirizza attualmente a
[`earendil-works/pi`](https://github.com/badlogic/pi-mono).
È stato esaminato il commit
[`26293e74f664c2cfb731b22a653cccf852830e00`](https://github.com/earendil-works/pi/commit/26293e74f664c2cfb731b22a653cccf852830e00).

Pi mette nome, descrizione e percorso nel prompt. Il codice chiede di usare
`read`, oppure `bash` quando appropriato, per leggere la skill pertinente.
L'oggetto runtime contiene nome, descrizione, percorso, directory, provenienza e
`disableModelInvocation`; non contiene dipendenze fra skill.
[Tipo e parser](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/src/core/skills.ts#L67-L82),
[parser e istruzioni al modello](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/src/core/skills.ts#L327-L385).

L'invocazione esplicita è diversa: `_expandSkillCommand` riconosce
`/skill:nome`, legge il file direttamente, rimuove il frontmatter e inserisce
le istruzioni in un blocco `<skill>`. Non serve una decisione del modello per
caricare quella skill. Il metodo espande solo il comando selezionato, senza
ricorsione sulle istruzioni contenute nel file.
[Implementazione dell'espansione](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/src/core/agent-session.ts#L2173-L2201).

La documentazione ammette esplicitamente che il modello può non caricare una
skill pertinente e suggerisce `/skill:nome` per forzarne il caricamento.
Questo forza A, non dimostra che anche B verrà letta.
[Documentazione: Understand how skills load](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/docs/skills.md#understand-how-skills-load).

## Le istruzioni effettivamente inviate al modello

Questa sezione distingue il prompt del catalogo, la descrizione del tool e
l'involucro delle istruzioni caricate. Gli estratti sono brevi; i collegamenti
portano ai blocchi completi fissati a commit. Le valutazioni riguardano questi
blocchi, non ogni istruzione generale presente nell'agente.

### Codex: uso obbligatorio e lettura prima delle azioni

Il prompt generato rende obbligatoria la skill nominata dall'utente o
chiaramente pertinente; più menzioni richiedono tutte le skill indicate.
Richiede che l'agente principale legga interamente il documento prima delle
azioni del compito e legga personalmente ogni riferimento necessario prima di
agire su quelle istruzioni. Chiede anche il minimo insieme pertinente, un ordine
di utilizzo e un breve annuncio. L'accesso varia fra filesystem e `skills.read`.
L'estratto che esplicita l'ordine temporale è:

> After deciding to use a skill, the main agent must read its `SKILL.md` completely before taking task actions.

[Prompt completo e varianti](https://github.com/openai/codex/blob/464add49587a7cb6032d5ab04faa8a1585947853/codex-rs/ext/skills/src/catalog_prompt.rs#L7-L40).

Il catalogo ha ruolo `developer`; il frammento delle istruzioni selezionate ha
ruolo `user`. Quest'ultimo è delimitato da `<skill>` e include nome, percorso,
eventuali dati di accesso e contenuto. Non è solo una lista di file da leggere.
[Rendering dei frammenti](https://github.com/openai/codex/blob/464add49587a7cb6032d5ab04faa8a1585947853/codex-rs/ext/skills/src/fragments.rs#L43-L145).

La descrizione di `skills.read` riguarda la lettura paginata, i parametri di
accesso e i percorsi; non aggiunge un ordine di esecuzione delle istruzioni
restituite o di caricamento delle skill nominate nel corpo.
[Descrizione del tool di lettura](https://github.com/openai/codex/blob/464add49587a7cb6032d5ab04faa8a1585947853/codex-rs/ext/skills/src/tools/read.rs#L104-L109).

Nel blocco letto non compare una traduzione di «Call the Skill tool» in una
chiamata obbligatoria successiva, né una regola distinta sulle dipendenze fra
skill. La disciplina riguarda uso delle skill e lettura delle istruzioni prima
delle azioni; non dice letteralmente «prima di qualsiasi risposta».
[Istruzioni d'uso](https://github.com/openai/codex/blob/464add49587a7cb6032d5ab04faa8a1585947853/codex-rs/ext/skills/src/catalog_prompt.rs#L7-L40).

### OpenCode v1: catalogo, tool e workflow

Per v1 è stato esaminato il tag
[`v1.19.3`, commit `e3c4eb242585dcd77be1a867391b7c25fd47ce52`](https://github.com/anomalyco/opencode/tree/e3c4eb242585dcd77be1a867391b7c25fd47ce52).
Il blocco del catalogo introduce istruzioni e workflow specializzati, poi dice:

> Use the skill tool to load a skill when a task matches its description.

Segue un elenco XML con nome, descrizione e percorso. Non impone in questo
blocco una scadenza prima della risposta né una chiamata a catena.
[Prompt del catalogo](https://github.com/anomalyco/opencode/blob/e3c4eb242585dcd77be1a867391b7c25fd47ce52/packages/opencode/src/session/system.ts#L107-L119),
[formato dell'elenco](https://github.com/anomalyco/opencode/blob/e3c4eb242585dcd77be1a867391b7c25fd47ce52/packages/opencode/src/skill/index.ts#L321-L341).

La descrizione del tool precisa che il risultato introduce istruzioni nel
contesto, può contenere workflow dettagliati e riferimenti locali e richiede un
nome presente nel catalogo:

> Use this tool to inject the skill's instructions and resources into current conversation.

[Descrizione completa del tool](https://github.com/anomalyco/opencode/blob/e3c4eb242585dcd77be1a867391b7c25fd47ce52/packages/opencode/src/tool/skill.txt).

Il risultato usa `<skill_content name="…">`, un titolo `# Skill: …`, il corpo
delle istruzioni e la directory; spiega come risolvere i percorsi relativi e
include `<skill_files>` con un campione di percorsi. Non aggiunge una formula
«esegui immediatamente» o «carica ogni dipendenza prima di rispondere».
[Costruzione del risultato](https://github.com/anomalyco/opencode/blob/e3c4eb242585dcd77be1a867391b7c25fd47ce52/packages/opencode/src/tool/skill.ts#L45-L66).

### OpenCode v2: stessa regola, riconoscimento dell'invocazione diretta

Il prompt del catalogo conserva la stessa regola della v1 e aggiunge che una
skill invocata direttamente dall'utente compare già come `<skill_content>` e
non richiede una seconda invocazione. Questo chiarisce il significato del blocco
iniettato, senza specificare la gestione di altre skill nominate nel suo corpo.
[Prompt completo](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/skill/instructions.ts#L25-L35).

La descrizione del tool lo presenta così:

> Load a specialized skill's instructions and resources into the current conversation when the task at hand matches its description.

Richiede un ID disponibile oppure indicato esplicitamente dall'utente; il
risultato mantiene l'involucro, la directory e il campione di file descritti
sopra. Nei blocchi esaminati non aggiunge obblighi temporali o di caricamento
ricorsivo.
[Descrizione del tool](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/tool/plugin/skill.ts#L11-L28),
[risultato](https://github.com/anomalyco/opencode/blob/d8148796dc74a342c96c7b4febf03dc664e1058c/packages/core/src/skill.ts#L36-L74).

### Pi: lettura per corrispondenza, espansione esplicita nel programma

Il blocco generato introduce istruzioni specializzate e dice:

> Use the read tool to load a skill's file when the task matches its description.

Le varianti indicano `bash` o non nominano un tool quando il lettore è indiretto.
Poi spiega la risoluzione dei percorsi relativi e presenta nome, descrizione e
percorso in `<available_skills>`. Il blocco non ordina la lettura prima della
risposta né disciplina esplicitamente catene fra skill.
[Generazione completa del prompt](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/src/core/skills.ts#L348-L387).

Non c'è qui una descrizione di tool `skill`: il modello utilizza un lettore di
file. Per l'invocazione esplicita il programma inserisce un blocco con nome e
percorso, seguito dalle istruzioni e dagli eventuali argomenti. Il messaggio
aggiunto dall'espansione precisa:

> References are relative to

seguito dalla directory della skill. Non aggiunge una direttiva a eseguire
immediatamente ogni chiamata menzionata nelle istruzioni.
[Espansione del comando](https://github.com/earendil-works/pi/blob/26293e74f664c2cfb731b22a653cccf852830e00/packages/coding-agent/src/core/agent-session.ts#L2173-L2201).

### Quale formulazione generale provare qui

La distinzione osservata è fra **identificare contenuto come istruzioni**
(involucri di tutti e tre gli agenti), **far leggere la skill pertinente**
(OpenCode e Pi) e **imporre uso e ordine di lettura prima delle azioni** (Codex).
Nessuno dei blocchi confrontati contiene esattamente la nostra regola sulla
«prossima azione» per una dipendenza.

Una formulazione candidata, derivata da queste pratiche ma non copiata da un
prodotto e non ancora validata, è:

```text
Use skills explicitly requested by the user or required for the task.
Load their complete instructions with skill(name=...) before acting on them.
Follow loaded skill instructions, including required skills and references.
```

La terza frase rende esplicito che caricare il testo comporta seguirne le
istruzioni, anche quando richiedono un'altra skill; non introduce eccezioni per
`grill-me` o per domande di chiarimento. Va confrontata con il testo attuale
nelle stesse prove: le fonti spiegano la scelta della formulazione, non ne
dimostrano l'affidabilità.

## Standard e conseguenze per questo progetto

La specifica Agent Skills elenca `name`, `description`, `license`,
`compatibility`, `metadata` e `allowed-tools`: non definisce un campo standard
per dipendenze fra skill. `metadata` permette estensioni, ma non assegna loro
automaticamente una semantica di caricamento.
[Agent Skills specification](https://agentskills.io/specification).

La guida per chi implementa un client distingue invece due pratiche: il modello
legge il file o usa un tool dedicato nella selezione implicita; il programma
risolve e inserisce le istruzioni nell'attivazione esplicita. Suggerisce inoltre
un involucro riconoscibile, per esempio `<skill_content>`, per le istruzioni
caricate. [Adding skills support](https://agentskills.io/client-implementation/adding-skills-support).

Nel nostro esempio, l'input viene passato a `UserMessage` come testo: non viene
interpretato come comando per inserire preventivamente una skill. Pertanto
`/grill-me` nella chat dimostrata non equivale al `/skill:grill-me` di Pi.
[Ciclo della chat](../../examples/demo-chat.php#L53-L68).
L'ADR esistente descrive già il caricamento a catena come guida al modello e
specifica che il toolkit non analizza il testo per caricare dipendenze o imporre
il completamento prima della risposta.
[ADR-0002](../adr/0002-single-resource-reading.md).

**Proposta derivata dalla ricerca, non garanzia di risoluzione:** prima di
introdurre nuovi metadati, confrontare il nostro formato di caricamento con un
blocco esplicito che identifichi istruzioni e skill, e separare nei test
«caricare A richiesta dall'utente» da «seguire A caricando B». Se vogliamo una
vera sintassi di comando, implementarne l'espansione nell'esempio è una pratica
riscontrata in Pi. Questi interventi non garantiscono la catena A → B.

Un risolutore di dipendenze dichiarate potrebbe renderne deterministico il
caricamento, ma sarebbe **una scelta progettuale aggiuntiva di questo toolkit**,
non una soluzione comune dimostrata dai tre prodotti esaminati. Per affermare
che migliori il comportamento servirebbero implementazione e verifiche dedicate.

## Limiti della verifica

Sono state lette documentazione ufficiale e implementazioni dei percorsi
indicati. Non sono stati eseguiti benchmark comparabili dei tre agenti, né è
stata verificata ogni estensione o variante commerciale. Il confronto riguarda
il meccanismo di caricamento, non l'affidabilità relativa dei modelli. Nessun
file di implementazione del progetto è stato modificato per questa ricerca.
