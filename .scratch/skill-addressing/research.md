# Indirizzi delle skill e percorsi delle risorse

Studio iniziale del 6 ottobre 2026, seguito dalla discussione e dal refactoring locale descritti nella sezione seguente. Le sezioni di analisi conservano il contratto e le alternative esaminati prima del refactoring; per il contratto corrente vedere README e ADR-0003.

## Stato dopo la discussione

Il lavoro prosegue in locale, senza push o aggiornamenti delle PR.
La direzione corrente mantiene le URI per identificare le skill; la proposta
`source + skill` in ADR-0002 è stata ritirata prima dell'implementazione.

Per supportare gli script salvati nel database, le guidelines guideranno l'agente
a recuperare i file necessari, ricostruirne la struttura in una directory
temporanea nell'ambiente di esecuzione ed eseguire il comando da quella root.
Questo richiede tool dell'applicazione capaci di scrivere file ed eseguire comandi.
La libreria deve poter elencare le risorse di una skill; la proposta è includere
l'elenco nella risposta del tool di attivazione insieme a `SKILL.md`.

La [guida ufficiale sugli script](https://agentskills.io/skill-creation/using-scripts#referencing-scripts-from-skillmd)
indica esplicitamente che i comandi degli script sono eseguiti dalla root della
skill. La preparazione di una directory temporanea per contenuti nel database è
una nostra soluzione d'integrazione, non un meccanismo prescritto dallo standard.

Approvato e implementato localmente il refactoring con `Location` e `Uri` in
`src/`: riferimenti URI relativi anche per le risorse, conversioni centralizzate e `ResourcePath`
dedicato ai percorsi già decodificati. `scripts/my%20script.py` seleziona ora
`scripts/my script.py`. I controlli filesystem continuano a usare `realpath()`.

Da definire per il successivo lavoro sull'esecuzione:

- Definire il contratto per elencare le risorse e il formato dell'attivazione.
- Verificare come trasferire risorse binarie: l'attuale tool di lettura è orientato
  al testo e non basta assumere che tutti gli asset siano script o Markdown.

Il resto dello studio descrive l'analisi iniziale e le alternative considerate;
la raccomandazione iniziale non costituisce approvazione del nuovo contratto.

## Raccomandazione iniziale

Conserverei la selezione della skill tramite la location pubblicata nel catalogo e il percorso relativo separato per leggere una risorsa. Sposterei invece dentro la libreria le conversioni che oggi deve conoscere chi configura il filesystem o integra l'esecuzione locale.

In pratica, lo sviluppatore dovrebbe poter scrivere una directory PHP normale; il modello dovrebbe scegliere una location già fornita; chi scrive una skill dovrebbe continuare a indicare i file relativi alla skill. Le conversioni tra directory, indirizzi e chiavi database sono lavoro degli adapter.

Non eliminerei `rawurlencode()` per rendere più corto l'adapter: serve alla rappresentazione che abbiamo scelto e occupa poco codice. Eliminerei la necessità di copiarlo nel quick start. Non aggiungerei un sistema di identificatori numerici, un router per prefissi o URI completi per ogni risorsa senza un'esigenza nuova che giustifichi il cambio di contratto.

C'è un limite reale da rendere esplicito: oggi il tool legge nomi di file, non interpreta automaticamente destinazioni di link Markdown. Un link con `%20` può quindi richiedere una conversione prima della chiamata. Questo problema esiste indipendentemente dal formato scelto per identificare la skill.

## Prima di tutto: che cosa rappresentano i due argomenti

Consideriamo questo database:

| skill_identifier | path | content |
| --- | --- | --- |
| my skill | SKILL.md | Le istruzioni della skill |
| my skill | references/my guide.md | Una guida con spazio nel nome |
| my skill | references/my%20guide.md | Un'altra guida: il nome contiene letteralmente `%20` |

L'adapter pubblica nel catalogo `db://team/my%20skill/`. La chiamata corrente è:

```php
$storage->read(
    'db://team/my%20skill/',
    'references/my guide.md',
);
```

Il primo valore sceglie la skill. Il secondo sceglie una riga all'interno di quella skill. L'adapter converte il primo nel valore `my skill`; il secondo resta `references/my guide.md`.

Se il secondo valore fosse `references/my%20guide.md`, leggerebbe l'altra riga. Non sono due modi intercambiabili per scrivere lo stesso input: nel secondo argomento `%20` è parte del nome. Questo è verificato dal codice e dai test, non una necessità imposta dal database. [Adapter PDO](../../src/Storage/DatabaseSkillStorage.php), [test di identità](../../tests/DatabaseSkillIdentityTest.php).

Quindi l'obiezione dell'utente coglie un punto importante: **anche un percorso può essere rappresentato secondo le regole URI**. Non basta chiamarlo `path` per stabilire se `%20` sia uno spazio. Bisogna dichiarare quale rappresentazione accetta quella specifica interfaccia. La nostra accetta un indirizzo nel primo campo e il nome relativo nel secondo.

Il repository non separa `db`, `team` e `my%20skill`: cerca l'intera stringa nel catalogo e ritrova l'adapter proprietario. Questo è compatibile con avere URI come stringhe pubbliche; non richiede un parser URI nel repository. Anche trasformare la stringa in un oggetto PHP non cambierebbe, da solo, questa scelta. [SkillRepository](../../src/SkillRepository.php).

## Che cosa richiedono davvero gli standard

La specifica Agent Skills definisce una directory con `SKILL.md` e file di supporto; i riferimenti sono relativi alla root della skill. Non prescrive `db://`, una classe PHP per gli indirizzi o il nostro contratto `read(location, path)`. Il nome dichiarato ha vincoli, ma la nostra chiave database può essere diversa da quel nome. [Agent Skills, specifica](https://agentskills.io/specification).

La guida ufficiale ammette accesso tramite filesystem oppure tool dedicati e origini remote. Mostra directory native per l'accesso locale; un tool di attivazione può fornire la directory nel risultato. Suggerisce anche precedenza tra skill omonime: il progetto ha invece deliberatamente scelto di esporle entrambe. È una scelta della libreria, non una conseguenza obbligata dello standard. [Guida di integrazione](https://agentskills.io/integrate-skills).

RFC 3986 distingue il percorso di un URI da un nome del filesystem. Spazi e percentuali letterali richiedono una rappresentazione appropriata; i componenti vanno separati prima della decodifica. Non bisogna decodificare due volte. Il confronto esatto tra stringhe è un criterio ammesso, pur senza riconoscere tutte le equivalenze. Un URI identifica una risorsa: non garantisce che sia recuperabile o eseguibile. [RFC 3986, §§ 1.2.2, 2, 3.3 e 6](https://www.rfc-editor.org/rfc/rfc3986.html).

RFC 8089 tratta esplicitamente la conversione tra URI `file:` e nomi del filesystem. Il progetto supporta un sottoinsieme più stretto: per esempio rifiuta `localhost`, che lo standard contempla. È quindi più preciso descriverlo come supporto a una forma locale specifica che come implementazione completa dello standard. [RFC 8089, §§ 2–4](https://www.rfc-editor.org/rfc/rfc8089.html), [FileSystemSkillStorage](../../src/Storage/FileSystemSkillStorage.php).

PHP fornisce `rawurlencode()` per codificare dati destinati a un URI. Non è un risolutore di percorsi. `realpath()` risolve invece symlink e segmenti del filesystem e può fallire se il percorso non esiste; non sostituisce la normalizzazione delle chiavi database. [Manuale rawurlencode](https://www.php.net/manual/en/function.rawurlencode.php), [manuale realpath](https://www.php.net/manual/en/function.realpath.php).

## Compatibilità con il ramo precedente

Sul ramo locale `1.x`, `FileSystemSkillStorage` accettava direttamente `skillsRoot` come percorso nativo. La selezione per location e il mount URI appartengono alle modifiche della PR #10: non sono un vincolo storico immutabile della libreria. Il confronto è stato verificato con `git show 1.x:src/Storage/FileSystemSkillStorage.php`; la [specifica location](../skill-locations/spec.md) autorizza esplicitamente breaking changes.

Una factory da directory recupererebbe quell'ergonomia senza ripristinare la selezione ambigua per nome. La raccomandazione di mantenere i due argomenti non dipende solo dal costo della migrazione: evita anche che ogni lettura debba comporre un indirizzo o esporre una terza coordinata al modello. Se fosse ancora tutto da progettare, l'alternativa sorgente + chiave sarebbe comunque competitiva; oggi un cambio integrale dovrebbe portare vantaggi misurabili oltre alla rimozione di poche conversioni interne.

## Dove passa oggi la complessità

| Interlocutore | Cosa deve fare oggi | Valutazione |
| --- | --- | --- |
| Sviluppatore che configura il filesystem | Convertire una directory come `__DIR__` in un mount `file:///` codificato | È una conversione ripetitiva che la libreria dovrebbe assumersi |
| Sviluppatore che configura PDO | Scegliere un mount come `db://team/`, fornire PDO e tabella | La sorgente va comunque distinta; il mount è un modo ragionevole di farlo |
| Autore di uno storage | Emettere location uniche e tradurle nelle proprie chiavi | La seam è piccola, ma richiede un contratto preciso sulle stringhe |
| Chiamante PHP | Selezionare dal catalogo, poi usare `Skill::readResource()` | Non deve manipolare la location per leggere file |
| Modello | Copiare la location e indicare il percorso relativo | Due valori con ruoli diversi, entrambi già disponibili nei casi semplici |
| Integrazione di esecuzione | Convertire una location locale in directory nativa | Oggi parte di questo lavoro è delegata alle istruzioni al modello |
| Utente finale della chat | Scegliere la skill pertinente, eventualmente tra due sorgenti | Non dovrebbe conoscere codifiche URI o nomi PDO |

Le proprietà dei due tool contengono già un elenco delle location valide. Il catalogo mostra nome, descrizione e location. Non serve introdurre un altro codice solamente per permettere la selezione. [SkillTool](../../src/Tools/SkillTool.php), [SkillResourceTool](../../src/Tools/SkillResourceTool.php), [SkillToolkit](../../src/Tools/SkillToolkit.php).

Per chi usa PHP, la forma naturale resta:

```php
// Interfaccia esistente: selectedLocation proviene dal catalogo.
$skill = $repository->get($selectedLocation);
$instructions = $skill->readDocument();
$guide = $skill->readResource('references/my guide.md');
```

Non occorre che questo chiamante sappia se dietro ci sia PDO, una directory o un adapter remoto.

## Evidenze concrete del progetto

La prova locale effettuata durante lo studio, con SQLite reale e una directory temporanea, ha confermato:

- `my skill` è pubblicata come `db://team/my%20skill/`.
- `references/my guide.md` e `references/my%20guide.md` leggono risorse distinte.
- `db://team/my skill/` non seleziona la skill nel repository.
- Costruire un mount con `'file://'.$root.'/'` fallisce se `$root` contiene uno spazio; il mount codificato funziona.

La prova è stata eseguita da `/tmp/skill-addressing-probe.php`; è un esperimento temporaneo, non un nuovo test mantenuto nel repository. I contratti di encoding e identità sono già coperti anche da [FileSystemSkillStorageTest](../../tests/Storage/FileSystemSkillStorageTest.php), [DatabaseSkillStorageTest](../../tests/Storage/DatabaseSkillStorageTest.php) e [DatabaseSkillIdentityTest](../../tests/DatabaseSkillIdentityTest.php).

C'era inoltre una discrepanza: il [README](../../README.md) codificava `__DIR__`, mentre l'esempio ora chiamato [demo.php](../../examples/demo.php) concatenava direttamente `'file://'`. Spostare l'esempio in una directory con spazi poteva quindi rompere la configurazione prima ancora di coinvolgere il modello. L'esempio ora usa `Uri::fromDirectory()`.

Non sono stati eseguiti esperimenti con modelli reali. I test con `FakeAIProvider` verificano gli argomenti e i risultati previsti, non misurano quanto spesso un modello riesca a costruire o copiare gli indirizzi. Le valutazioni seguenti riguardano il numero di decisioni richieste all'interfaccia, non tassi di errore empirici.

## Confronto tra sei alternative

Le chiamate marcate «proposta» non esistono nel progetto.

### A. Location del catalogo + percorso relativo, come oggi

```json
{"location":"db://team/my%20skill/","path":"references/my guide.md"}
```

La skill e la risorsa restano separate. Il chiamante copia la location; il codice dello storage gestisce il backend. Non bisogna ricostruire un indirizzo per ogni file. Le skill omonime mantengono sorgenti distinte, senza fallback.

Costo: due rappresentazioni diverse vanno spiegate, e le destinazioni URI nei link Markdown non sono automaticamente nomi di file. La configurazione filesystem e l'esecuzione locale hanno bisogno di conversioni oggi troppo visibili al chiamante.

**Valutazione:** migliore punto di partenza per questa libreria, purché la libreria nasconda le conversioni operative e sia onesta sul significato del secondo argomento.

### B. Un URI completo per ogni risorsa

```json
{"uri":"db://team/my%20skill/references/my%20guide.md"}
```

Proposta: il tool avrebbe un unico indirizzo. Può essere utile per un sistema che espone risorse come link autonomi, con cataloghi di tutte le risorse o integrazioni già basate su indirizzi completi.

Qui, però, le istruzioni contengono percorsi relativi e lo storage non elenca tutte le risorse. Qualcuno deve costruire l'URI, stabilire dove finisce la skill, gestire i mount sovrapposti e preservare l'owner già selezionato. Un URI completo non elimina containment e symlink. Affidare questi passaggi al modello sposta la complessità fuori dal codice senza prova di un beneficio.

**Valutazione:** non consigliata per l'interfaccia attuale. Se in futuro serve esportare URI di risorse, la libreria può produrli esplicitamente senza sostituire necessariamente il tool corrente.

### C. Sorgente + identificatore backend + percorso

```json
{"source":"team","skill":"my skill","path":"references/my guide.md"}
```

Proposta: tre campi diretti; gli spazi sono normali stringhe JSON. Le omonimie si risolvono con `source`, non con una convenzione di codifica. È una vera alternativa strutturale agli URI, non un errore di sintassi.

Occorre però introdurre la registrazione e unicità delle sorgenti, associare ogni coppia all'adapter e modificare repository, catalogo, tool e contratto custom storage. Il filesystem dovrebbe scegliere che cosa esporre come identificatore: nome relativo, directory completa o altra chiave. La distinzione tra nome dichiarato e chiave backend resta necessaria. Il selettore PHP diventerebbe una coppia o un oggetto che la rappresenta.

**Valutazione:** sarebbe una buona scelta iniziale per un prodotto organizzato attorno a sorgenti logiche esplicite. Risolve la serializzazione rendendo pubblico un campo in più; non è chiaramente più semplice per l'intero progetto già esistente.

### D. Identificatori limitati a lettere, numeri e trattini

```json
{"location":"db://team/my-skill/","path":"references/my guide.md"}
```

Proposta: la parte identificativa non necessita normalmente di percent encoding. Restano URI di mount locali con eventuali spazi nelle directory superiori e nomi di risorse liberi. Non elimina quindi tutte le conversioni della libreria.

Imporre questi limiti ai nomi dichiarati non basta: directory, backend key e frontmatter oggi sono deliberatamente distinti. Imporli ai backend key richiede migrazione o rifiuto di dati supportati; trasformare automaticamente `my skill` in `my-skill` può creare collisioni. Anche gli identificatori `.` e `..` richiederebbero una nuova politica.

**Valutazione:** utile come convenzione volontaria di un'applicazione che controlla i propri dati, poco convincente come restrizione generale della libreria.

### E. Un codice assegnato dal catalogo

```json
{"skill":"skill-42","path":"references/my guide.md"}
```

Proposta: il catalogo dice che `skill-42` è la skill caveman della sorgente team. Il modello ricopia quel codice. Il valore non va interpretato: funziona come il numero di una prenotazione.

Il repository deve mantenere la corrispondenza codice → skill e definire se il codice dura una sessione, un processo o più deploy. I numeri basati sull'ordine cambiano se cambia il catalogo; codici persistenti richiedono un'identità durevole; hash richiedono comunque scegliere i dati che definiscono l'identità. Per distinguere due caveman bisogna mostrare anche la sorgente, altrimenti il codice non aiuta l'utente a decidere. Inoltre si perde l'informazione locale utile all'esecuzione, che va restituita separatamente.

**Valutazione:** sensata per cataloghi molto grandi o interfacce con selezione guidata, ma non risolve gratis il problema. Le location correnti sono già codici da ricopiare dal punto di vista del repository.

### F. Location URI + riferimento relativo URI

```json
{"location":"db://team/my%20skill/","reference":"references/my%20guide.md"}
```

Proposta: mantiene la skill separata dalla risorsa, ma anche il secondo campo segue le regole URI. È distinta dall'alternativa B: non richiede comporre un indirizzo completo né cambiare il routing del repository. È la risposta più diretta alla richiesta di usare una sola rappresentazione nei due argomenti.

Un nome reale `references/my guide.md` diventerebbe `references/my%20guide.md`; un nome reale `references/my%20guide.md` diventerebbe `references/my%2520guide.md`. Quindi non serve indovinare il significato della percentuale: il campo è dichiaratamente un riferimento URI e la conversione avviene una volta. Bisogna comunque definire frammenti, query, schemi e navigazione, quindi applicare i controlli del backend sul percorso decodificato.

Vantaggio: destinazioni di link URI e input del tool possono avere la stessa rappresentazione. Costo: chi passa nomi reali a questa interfaccia deve convertirli; l'encoding delle risorse aumenta invece di sparire. Lo schema Agent Skills contiene anche riferimenti testuali e comandi, non soltanto destinazioni di link. È dunque un cambiamento di contratto, non una normalizzazione trasparente di quello attuale.

F potrebbe riguardare soltanto il tool: questo convertirebbe `reference` e chiamerebbe l'attuale `Skill::readResource($path)`. Il chiamante PHP continuerebbe allora a usare nomi reali e il contratto degli storage resterebbe invariato. Sarebbe una differenza esplicita tra tool e interfaccia PHP, da valutare in base a come gli autori indicano le risorse. Inoltre risolvere `..` come URI prima del filesystem cambierebbe il comportamento dei symlink dimostrato sotto: la modalità di decodifica e la risoluzione nel backend vanno progettate separatamente.

**Valutazione:** alternativa valida se l'interfaccia principale deve consumare riferimenti URI, specialmente link. Per questa libreria terrei il nome relativo reale come operazione di base e valuterei un ingresso distinto per i riferimenti URI se l'importazione dei link lo richiede. Non cambierei silenziosamente la semantica di `path`.

| Alternativa | Cosa passa chi legge una risorsa | Costo principale | Giudizio |
| --- | --- | --- | --- |
| A: location + nome relativo | Location copiata, nome reale del file | Rendere espliciti i due tipi di input | Raccomandata con configurazione più semplice |
| B: URI completo | Un indirizzo di risorsa costruito o fornito | Composizione e riconoscimento della skill proprietaria | Utile per un sistema già basato su URI di risorse |
| C: sorgente + chiave + nome relativo | Tre stringhe di dati | Nuova registrazione e identità per coppie | Migliore alternativa se si ridisegna il contratto |
| D: nomi limitati | Location semplice, nome reale del file | Restrizione o migrazione dei dati, mount ancora da codificare | Convenzione applicativa, non regola generale |
| E: codice catalogo + nome relativo | Codice copiato, nome reale del file | Stabilità del codice e metadati per distinguere le sorgenti | Da valutare per cataloghi grandi o UI guidate |
| F: location + riferimento URI | Location copiata, riferimento URI della risorsa | Codificare i nomi reali; definire query/frammenti | Valida se la sorgente principale sono link URI |

## Markdown: il caso che una semplificazione non deve nascondere

CommonMark mantiene l'URL escaping dentro le destinazioni dei link; non lo interpreta come un nome nativo da passare a una libreria di storage. Un link Markdown e un percorso scritto in codice possono quindi trasmettere rappresentazioni diverse. [CommonMark 0.31.2, link destinations](https://spec.commonmark.org/0.31.2/#link-destination).

Esempio analitico:

```markdown
Leggi il file `references/my guide.md`.

Oppure apri [la guida](references/my%20guide.md).
```

Entrambi possono riferirsi alla guida con spazio. Tuttavia il nostro tool, ricevendo direttamente `references/my%20guide.md`, cerca il nome contenente la percentuale. Cambiare il secondo argomento perché decodifichi sempre romperebbe invece le risorse che si chiamano davvero così.

Non consiglio di provare prima il nome letterale e poi quello decodificato: quando esistono entrambi, l'identità della risorsa diventerebbe dipendente dal contenuto dello storage. Lo stesso vale per eliminare `#section` indiscriminatamente: in una destinazione di link è un frammento, ma `#` può essere parte del nome richiesto come percorso letterale.

Per il contratto corrente: `path` è il nome relativo reale. Le istruzioni del tool dovrebbero dirlo esplicitamente e non promettere che si possa copiare ogni destinazione Markdown senza interpretarla.

Se l'importazione automatica di link Markdown diventa un requisito, farei una funzione o un'interfaccia distinta che riceve esplicitamente un riferimento URI e lo converte. Esempio puramente proposto:

```php
// Non implementato: la modalità è esplicita, non indovinata dal carattere "%".
$skill->readResource('references/my%20guide.md'); // nome letterale
$skill->readReference('references/my%20guide.md'); // destinazione URI → nome con spazio
```

Non suggerisco di aggiungere subito entrambi i metodi: prima occorre decidere gestione dei frammenti, schemi ammessi e documenti annidati. La specifica di progetto mantiene come base la root della skill anche dentro i documenti di supporto; un normale resolver basato sulla directory del documento cambierebbe questa semantica. [Spec location](../skill-locations/spec.md).

## Identità, filesystem e adapter futuri

Le proprietà da conservare sono più importanti della forma testuale: una selezione appartiene a un solo adapter; due nomi uguali non si sostituiscono; una risorsa mancante non viene cercata altrove; i duplicati di identità falliscono; la lettura resta nella skill selezionata. Queste proprietà sono già implementate e sono indipendenti dal fatto che il selettore contenga `://`. [Spec location](../skill-locations/spec.md), [spec PDO](../db-skill-storage/spec.md).

Le location attuali sono stabili finché restano gli stessi mount e identificatori. Non sono identità permanenti del contenuto: rinominare una directory cambia la location; cambiare il contenuto no. Due mount PDO sullo stesso database e tabella espongono gli stessi dati con indirizzi diversi. Per garantire identità attraverso spostamenti servirebbe un requisito e un'identità distinta, qualunque sia il formato pubblico scelto. [README](../../README.md).

Nel filesystem l'indirizzo pubblico segue il mount configurato, mentre il contenimento delle risorse usa la directory canonica. Un link alla directory della skill può puntare fuori dal mount; un link a una risorsa non può scappare dalla skill. Un refactoring delle URI non deve sostituire `realpath()` con `ResourcePath::normalize()`: sono implementazioni di semantiche diverse. [FileSystemSkillStorage](../../src/Storage/FileSystemSkillStorage.php), [ResourcePath](../../src/Storage/ResourcePath.php).

Un secondo esperimento locale (`/tmp/skill-symlink-probe.php`) rende concreta la differenza: `docs` è un symlink verso `references/nested`; esistono sia `references/guide.md` sia `guide.md`, con contenuti diversi e dentro la skill.

| Lettura | Risultato osservato |
| --- | --- |
| Filesystem: `docs/../guide.md` | Legge `references/guide.md` |
| Prima `ResourcePath::normalize()`, poi filesystem | Normalizza a `guide.md` e legge il file nella root |

Nessuno dei due percorsi esce dalla skill, ma non identificano lo stesso file. Centralizzare la normalizzazione testuale in tutti gli storage cambierebbe quindi un comportamento osservabile. Il riuso corretto riguarda gli adapter con chiavi testuali compatibili, non qualunque storage che esponga un parametro chiamato `path`.

Un adapter remoto può implementare la stessa seam senza rendere l'indirizzo un URL HTTP pubblico, senza esporre credenziali e senza permettere l'esecuzione. Il progetto non recupera risorse arbitrarie da Internet in base alla location. Aggiungere un URI parser generale nel repository non aiuterebbe il modello di proprietà già esistente. [SkillStorageInterface](../../src/Storage/SkillStorageInterface.php), [SkillRepository](../../src/SkillRepository.php).

C'è un caso limite dell'encoding PDO: gli identificatori `.` e `..` vengono rappresentati con `%2E` e `%2E%2E`. Il round trip dell'adapter funziona col confronto esatto. Un normalizzatore URI generico può però decodificare caratteri non riservati e trattare successivamente i punti come navigazione. Quindi non presenterei questi indirizzi come intercambiabili con qualsiasi elaborazione URI esterna. Se servirà questa interoperabilità, andrà rivisto quel formato con migrazione esplicita. [Adapter PDO](../../src/Storage/DatabaseSkillStorage.php), [RFC 3986, §§ 2.3 e 6.2.2](https://www.rfc-editor.org/rfc/rfc3986.html).

## Cambiamenti possibili, in ordine

### 1. Rendere naturale la configurazione locale

Proposta additiva, nome provvisorio:

```php
$filesystem = FileSystemSkillStorage::fromDirectory(__DIR__.'/skills');
$database = new DatabaseSkillStorage('db://team/', $pdo);

$repository = new SkillRepository($filesystem, $database);
```

La factory riceverebbe un percorso nativo assoluto, lo validerebbe e produrrebbe internamente la rappresentazione pubblica. Il costruttore URI esistente resterebbe disponibile: niente riconoscimento ambiguo di due formati nello stesso parametro. Il supporto POSIX/Windows andrebbe dichiarato e testato, senza presentare la factory come portabile più dell'adapter sottostante.

Questa modifica aumenta la depth del modulo: chi lo usa conosce meno regole, mentre encoding e validazione restano in un solo posto. Andrebbero aggiornati quick start ed esempi, compreso quello che oggi concatena `__DIR__` senza encoding. Non richiede cambiare le location pubblicate o i tool.

### 2. Esplicitare il contratto dei tool e dell'utente finale

La descrizione di `path` dovrebbe dire che vuole il percorso relativo del file, con spazi e percentuali letterali, non un URI completo. Il campo `location` va scelto dal catalogo. Per un'interfaccia con menu o slash command mostrerei «caveman — team» e «caveman — progetto», conservando la location come valore selezionato; non chiederei all'utente finale di scriverla.

I nomi visualizzati delle sorgenti possono essere aggiunti dall'applicazione senza inventare subito un nuovo sistema di identità nella libreria. Le eventuali informazioni di esecuzione devono restare distinte dalla descrizione della skill.

### 3. Togliere al modello la conversione per eseguire script locali

Oggi le guidelines gli dicono di validare e decodificare una location file. Prima di promettere esecuzione locale, l'host dovrebbe ottenere una directory nativa affidabile e sapere che il proprio execution tool la vede nello stesso ambiente.

Proposta da progettare separatamente: una capability locale opzionale fornita dall'adapter e consumata dall'integrazione di esecuzione, oppure una conversione validata interna all'esempio. Eviterei un obbligo `workingDirectory()` su ogni storage: per PDO e fonti remote quel concetto può non esistere. Non aggiungerei esecuzione o materializzazione automatica alla seam di sola lettura.

Il risultato di attivazione potrebbe includere informazioni locali strutturate accanto al documento quando disponibili. Sarebbe un cambiamento osservabile, da concordare; lo studio non modifica il contratto che oggi restituisce il documento originale.

### 4. Valutare separatamente l'accesso da link Markdown

Prima raccogliere casi concreti di skill importate con spazi, `%`, frammenti e documenti annidati. Se l'interpretazione automatica è necessaria, introdurla esplicitamente e testarla senza rendere ambigua la lettura dei nomi letterali. Non aggiungere euristiche di fallback.

### 5. Misurare prima di sostituire le location

Un piccolo confronto con modelli reali potrebbe usare gli stessi documenti e cataloghi, variando solo lo schema dei tool: A contro C o E. Casi minimi: due caveman, nome con spazio, percentuale letterale, guida assente nella sorgente scelta, richiesta da un link Markdown e script remoto non eseguibile. Misurare selezione corretta, trasformazioni degli argomenti e richieste fallite; non attribuire agli URI una penalità o un beneficio che i test attuali non misurano.

Il cambio complessivo di identità avrebbe senso se emergesse una necessità concreta di codici persistenti, sorgenti gestite dall'utente o cataloghi troppo ingombranti. Non è necessario per risolvere la difficoltà attuale del quick start.

## Decisione suggerita al maintainer

Approvare prima il principio: **la libreria gestisce la conversione tra percorsi nativi e indirizzi; i chiamanti selezionano skill dal catalogo e chiedono file per percorso relativo reale**.

Poi valutare una modifica limitata: factory da directory nativa, esempi coerenti e descrizioni dei due argomenti più precise. Mantenere il formato delle location e il contratto degli storage durante questo passaggio. Trattare separatamente le capacità di esecuzione locale e l'interpretazione dei link Markdown.

Questo studio non propone di cambiare silenziosamente il significato di `%20`, restringere i dati esistenti, eliminare i controlli di contenimento o affidare al modello un lavoro di conversione che può fare deterministicamente PHP.
