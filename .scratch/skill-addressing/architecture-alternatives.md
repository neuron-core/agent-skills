# Tre architetture per gli storage delle skill

Studio del 6 ottobre 2026, sul worktree locale dopo il refactoring iniziale
`SkillUri`. In seguito gli helper sono stati separati in `Location` e `Uri`;
i riferimenti al codice e gli esempi di A sono aggiornati ai nomi correnti.
Una successiva modifica locale ha spostato il confronto esatto di
`skill_identifier` e `path` nello schema database; i riferimenti nello studio
al filtro PHP descrivono la versione esaminata allora.
Stato: confronto progettuale, non scelta approvata né specifica da implementare.
Le tre alternative sono state esaminate separatamente e confrontate sullo stesso
scenario. Solo questo documento è stato aggiunto per lo studio; nessun push o
aggiornamento delle PR.

## Problema e perimetro confermati

Vogliamo rendere gli storage facili da usare e da mantenere. L'estrazione delle
conversioni in `SkillUri` ha tolto dettagli dagli adapter, ma ha esposto sei
metodi il cui ruolo non era immediato. Rinominare i metodi aiuta; decidere chi
deve conoscere quelle conversioni è il problema architetturale.

I chiamanti da considerare sono tre: l'applicazione che configura gli storage,
il repository e i tool che leggono le skill, e chi implementa un nuovo storage.
Possiamo modificare costruttori e interfacce PHP. I tool mantengono la selezione
mediante `location` URI completa e `path` URI relativa.

L'elenco delle risorse e la preparazione di directory temporanee per gli script
rimangono lavoro successivo. Usiamo l'elenco delle risorse solo per confrontare
come evolverebbe ciascun disegno, senza anticiparne il contratto completo.

Restano aperti alle proposte: responsabilità dei moduli, rappresentazione degli
indirizzi nel codice PHP e posizione della seam, cioè l'interfaccia attraverso
cui sostituiamo un adapter con un altro.

## Vincoli ricavati dal codice

| Vincolo da preservare | Evidenza locale |
| --- | --- |
| Il repository seleziona la location esatta e conserva l'adapter proprietario; niente routing per prefisso o fallback per nome. | [SkillRepository::get e buildCatalog](../../src/SkillRepository.php), [MultipleSkillStoragesTest](../../tests/MultipleSkillStoragesTest.php) |
| Il nome nel frontmatter è metadato; può differire dall'identificativo backend e può ripetersi. | [SkillRepositoryTest](../../tests/SkillRepositoryTest.php), [DatabaseSkillIdentityTest](../../tests/DatabaseSkillIdentityTest.php) |
| `%20` indica spazio, `%2520` un `%20` letterale, `+` resta `+`. Si decodifica una volta. | [SkillStorageUriTest](../../tests/Storage/SkillStorageUriTest.php) |
| URI delle skill canoniche ed esatte; riferimenti risorsa possono avere codifiche equivalenti, come `+` e `%2B`. | [DatabaseSkillIdentityTest](../../tests/DatabaseSkillIdentityTest.php), [SkillStorageUriTest](../../tests/Storage/SkillStorageUriTest.php) |
| Le risorse rifiutano URI assolute, query, fragment, slash codificati, NUL e backslash. `%23` e `%3F` sono invece nomi letterali validi. | [Uri](../../src/Uri.php), [SkillStorageUriTest](../../tests/Storage/SkillStorageUriTest.php) |
| Il filesystem risolve symlink e `..` con `realpath()` prima di verificare il confinamento. | [FileSystemSkillStorageTest](../../tests/Storage/FileSystemSkillStorageTest.php), test `test_parent_segments_are_resolved_after_following_a_directory_symlink` |
| Il database normalizza il percorso lessicalmente e confronta ID e path esatti in PHP oltre alla selezione SQL. | [DatabaseSkillStorage](../../src/Storage/DatabaseSkillStorage.php), [DatabaseSkillIdentityTest](../../tests/DatabaseSkillIdentityTest.php) |
| La directory di una skill può essere un symlink esterno al mount; la sua destinazione reale diventa il confine delle risorse. | [FileSystemSkillStorageTest](../../tests/Storage/FileSystemSkillStorageTest.php) |
| PDO e schema appartengono all'applicazione; niente modifiche agli attributi della connessione. | [DatabaseSkillStorageTest](../../tests/Storage/DatabaseSkillStorageTest.php) |
| Metadati conservati nel catalogo, contenuti riletti; discovery del repository lazy e rollback in caso di errore. | [DatabaseSkillToolkitTest](../../tests/DatabaseSkillToolkitTest.php), [SkillRepository::resolveCatalog](../../src/SkillRepository.php) |
| Testo UTF-8, contenuto vuoto ammesso, binari rifiutati; lettura degli script senza esecuzione. Errori attesi come `RuntimeException`, convertiti in messaggi dai tool. | [SkillStorageInterface](../../src/Storage/SkillStorageInterface.php), [SkillResourceTool](../../src/Tools/SkillResourceTool.php), test dei due adapter |
| Compatibilità PHP 8.1; il supporto corrente alle directory locali usa percorsi assoluti con `/`, senza supporto ai drive Windows. | [composer.json](../../composer.json), [Uri](../../src/Uri.php) |

Queste sono regole di questa libreria, non una dichiarazione che qualunque URI
debba rispettare lo stesso sottoinsieme.

Filesystem e PDO sono due dipendenze reali, verificabili localmente con directory
temporanee e SQLite in memoria. Le conversioni sono puro calcolo sulle stringhe.
Non serve un'interfaccia aggiuntiva per ciascuna funzione pura.

## Scenario identico per le tre proposte

L'applicazione fornisce `$pdo`, già connesso. Possiede schema e dati della tabella
`skills`, con colonne `skill_identifier`, `path`, `content`:

| skill_identifier | path | content |
| --- | --- | --- |
| `my skill` | `SKILL.md` | Documento valido con `name: writing` |
| `my skill` | `references/my guide.md` | `Guida DB` |

La directory `/app/skills/my skill/` contiene un altro `SKILL.md` con lo stesso
`name: writing` e `references/my guide.md` contenente `Guida FS`.
Esiste anche `/app/skills/secret.md`, esterno alla directory della skill.

L'app deve configurare entrambi gli storage, leggere `Guida DB` tramite
`db://team/my%20skill/`, leggere separatamente `Guida FS` e ricevere
`RuntimeException` provando `../secret.md`. Non deve mai scegliere l'altra skill
solo perché il nome dichiarato coincide.

Tutti gli esempi seguenti sono illustrativi. B e C non sono implementate né
eseguite. A descrive l'architettura locale esistente, ma gli snippet dello studio
non sono stati eseguiti: assumono i dati appena descritti. La verifica già
completata sul codice locale è `composer check`: 251 test, 737 asserzioni e
PHPStan senza errori.

## A — Gli storage interpretano le URI usando helper comuni

### Disegno e contratto

È l'architettura attuale: il repository parla direttamente con ciascun adapter.
Gli adapter usano `Location` e `Uri` per le conversioni e mantengono la responsabilità di
applicarle nel momento corretto.

```text
Applicazione / tool → repository → SkillStorageInterface
                                  ├─ FileSystemSkillStorage → filesystem
                                  └─ DatabaseSkillStorage   → PDO
                                     entrambi usano Location e Uri
```

```php
interface SkillStorageInterface
{
    /** @return list<string> URI complete canoniche delle skill. */
    public function list(): array;

    /** @throws RuntimeException */
    public function read(string $location, string $path): string;
}
```

`$path` è una URI relativa. I costruttori ricevono il mount completo; quello DB
riceve anche PDO e tabella. `list()` non è un'apertura obbligatoria prima di ogni
lettura. Il repository conserva la discovery; gli adapter mantengono il proprio
ciclo di vita attuale: snapshot filesystem e interrogazione DB a ogni `list()`.

La lettura DB inizia così:

```php
$identifier = Location::toSkillIdentifier($this->mount, $location);
$resourcePath = Uri::uriToPath($path);
// Seguono query, confronto esatto e verifica del contenuto.
```

La lettura filesystem decodifica allo stesso modo, ma passa il percorso ancora
contenente gli eventuali `..` a `realpath()`. Il controllo del testo restituito
rimane in ciascun adapter.

### Codice applicativo

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

function exampleA(PDO $pdo): void
{
    try {
        $repository = new SkillRepository(
            new FileSystemSkillStorage('file:///app/skills/'),
            new DatabaseSkillStorage('db://team/', $pdo, 'skills'),
        );
        $toolkit = new SkillToolkit($repository); // Registrabile nell'agente.

        $skill = $repository->get('db://team/my%20skill/');
        echo $skill->readResource('references/my%20guide.md'); // Guida DB
        echo $repository->get('file:///app/skills/my%20skill/')
            ->readResource('references/my%20guide.md'); // Guida FS

        $skill->readResource('../secret.md');
    } catch (RuntimeException $exception) {
        echo $exception->getMessage(); // Il tentativo di fuga viene rifiutato.
    }
}
```

L'app non deve imparare tutti i converter. Se parte da una directory nativa usa
`Uri::fromDirectory()`. Se parte da un nome letterale di risorsa usa
`Uri::pathToUri()`. Gli altri metodi servono prevalentemente agli
autori degli adapter.

### Depth, locality e costo

La **depth** per l'app è buona: due operazioni nascondono encoding, I/O e
confinamento. La **locality** delle regole di codifica è negli helper; quella
degli algoritmi filesystem/SQL è nel rispettivo adapter. Rimane distribuita la
regola «decodifica esattamente una volta»: l'helper non impedisce all'autore di
un adapter di saltare o ripetere la conversione.

Deletion test: togliendo `Location` e `Uri`, le conversioni ritornano duplicate nei due
adapter; togliendo gli adapter, lookup e confinamento finiscono nei chiamanti.
Questi moduli hanno una responsabilità utile. Non occorre introdurre oggetti
solo per avvolgere stringhe.

La futura enumerazione delle risorse richiederebbe un metodo sull'interfaccia e
su ciascun adapter; ciascuno dovrebbe codificare i nomi restituiti. Il chiamante
continuerebbe a usare i riferimenti ottenuti per leggere. Il costo di adozione di
A è minimo: è già il disegno corrente. Il limite è che ogni custom storage deve
comprendere sia il protocollo URI sia il proprio backend.

### Verifica

Testare i due adapter reali attraverso `list/read` e il repository attraverso
`get/readResource`. Con `$repository` costruito come sopra, il corpo del test è:

```php
$skill = $repository->get('db://team/my%20skill/');
$this->assertSame('Guida DB', $skill->readResource('references/my%20guide.md'));
$this->assertSame('Guida FS', $repository->get('file:///app/skills/my%20skill/')
    ->readResource('references/my%20guide.md'));
$this->expectException(RuntimeException::class);
$skill->readResource('../secret.md');
```

Nella fixture si usa una directory temporanea invece di `/app`. La matrice URI,
i symlink e le collation richiedono i test degli adapter già presenti; il solo
test felice del repository non basta a dimostrarne la correttezza.

## B — Gli indirizzi diventano oggetti con invarianti

### Disegno e contratto

La seam tra repository e adapter usa `SkillLocation` e `ResourceReference`.
Gli oggetti validano e conservano la rappresentazione; gli adapter ricevono i
tipi direttamente, senza un secondo contratto backend con stringhe letterali.

```text
Applicazione / tool con stringhe → repository / Skill
                                  costruiscono oggetti indirizzo
                                  → interfaccia storage tipizzata
                                    ├─ adapter filesystem
                                    └─ adapter PDO
```

```php
interface SkillStorageInterface
{
    /** @return list<SkillLocation> */
    public function locations(): array;

    /** @throws RuntimeException */
    public function read(
        SkillLocation $location,
        ResourceReference $resource,
    ): string;
}
```

`SkillLocation` espone factory `fromIdentifier($mount, $identifier)` e
`parse($mount, $uri)`, più `uri()` e `identifierFor($mount)`. Il parsing richiede
uguaglianza esatta con la URI canonica ricostruita: non corregge un alias del
chiamante. `identifierFor()` controlla che il mount sia quello previsto. Un
oggetto valido non prova che la skill esista.

`ResourceReference` espone `parse($uri)`, `fromPath($literalPath)`, `uri()` e
`decodedPath()`. Distingue esplicitamente una stringa già codificata da un nome
letterale. Valida e decodifica una sola volta; non risolve `..` o symlink. Può
preservare il riferimento originale anche quando due codifiche sono equivalenti.

Entrambi sono immutabili, implementabili in PHP 8.1 con proprietà `readonly` e
costruttore privato. Il repository indicizza `uri()` e conserva l'oggetto
scoperto. `get(string $location)` continua a cercare la stringa esatta: non prova
a interpretare mount di adapter sconosciuti.

L'applicazione conserva `Skill::readResource(string)`; questo metodo costruisce
`ResourceReference` e chiama lo storage con l'oggetto location già conservato.
Per l'autore dell'adapter DB, la lettura comincia così:

```php
$identifier = $location->identifierFor($this->mount);
$path = ResourcePath::normalize($resource->decodedPath());
// Query e confronto esatto restano nel database adapter.
```

I costruttori degli adapter possono mantenere il mount stringa attuale. La
conversione tra directory native e URI file resta implementazione del filesystem:
i due oggetti non diventano un accesso universale ai backend.

### Codice applicativo

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillStorage;
use NeuronAI\AgentSkills\Storage\FileSystemSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

function exampleB(PDO $pdo): void
{
    try {
        $repository = new SkillRepository(
            new FileSystemSkillStorage('file:///app/skills/'),
            new DatabaseSkillStorage('db://team/', $pdo, 'skills'),
        );
        $toolkit = new SkillToolkit($repository);

        $skill = $repository->get('db://team/my%20skill/');
        echo $skill->readResource('references/my%20guide.md'); // Guida DB
        echo $repository->get('file:///app/skills/my%20skill/')
            ->readResource('references/my%20guide.md'); // Guida FS

        $skill->readResource('../secret.md');
    } catch (RuntimeException $exception) {
        echo $exception->getMessage();
    }
}
```

Il codice applicativo è intenzionalmente uguale ad A: la differenza strutturale
è nel contratto degli adapter. Un chiamante diretto dello storage deve invece
passare i due oggetti; la facade applicativa non elimina questo costo per gli
autori di integrazioni avanzate.

### Depth, locality e costo

La depth è nella garanzia dei tipi: un adapter può ricevere solo una reference
già validata, e la distinzione URI/percorso diventa visibile a PHPStan. La
locality delle regole è nei due oggetti; I/O, confinamento e controllo testo
rimangono negli adapter. Nessun tipo rende sicuro un percorso senza consultare
il backend.

Deletion test: togliendo gli oggetti, controlli e distinzione delle
rappresentazioni tornano nei producer e negli adapter. Se fossero semplici
contenitori con getter, la nuova struttura non sarebbe giustificata.

Una futura enumerazione potrebbe produrre `ResourceReference::fromPath()` e
restituire oggetti agli altri moduli PHP, serializzandoli in URI per il modello.
La modifica riguarda interfaccia, adapter e facade. Questo acquista valore se
molti moduli conservano e trasformano indirizzi; ne acquista meno in un flusso
che riceve una stringa, legge il file e restituisce testo.

Costo di adozione medio-alto: cambio dell'interfaccia pubblica degli adapter,
discovery, `Skill`, fixture e test diretti. Ogni lettura crea una reference.
Non abbiamo misurazioni che rendano rilevante il costo di allocazione; il costo
evidente è cognitivo. Si introducono almeno due tipi e le loro factory senza
ridurre necessariamente il numero di metodi da capire.

### Verifica

Il test attraverso la facade rimane quello di A, perché è la stessa interfaccia
usata dall'app. Per il chiamante diretto dello storage, il test equivalente è:

```php
$storage = new DatabaseSkillStorage('db://team/', $pdo, 'skills');
$location = SkillLocation::parse('db://team/', 'db://team/my%20skill/');
$this->assertSame('Guida DB', $storage->read(
    $location,
    ResourceReference::parse('references/my%20guide.md'),
));
$this->expectException(RuntimeException::class);
$storage->read($location, ResourceReference::parse('../secret.md'));
```

La reference con `..` può essere sintatticamente valida: è l'adapter a rifiutare
la fuga. Si migrano i test esistenti verso il nuovo contratto, senza mantenere
in parallelo un vecchio protocollo solo per i test.

## C — Uno storage comune interpreta le URI, i backend leggono nomi reali

### Disegno e contratto

`MountedSkillStorage` implementa l'attuale contratto URI. Compone un backend con
un contratto diverso, basato su identificativi e percorsi letterali.

```text
Applicazione / tool → repository → MountedSkillStorage
                                  URI e controlli comuni
                                  → SkillBackendInterface
                                    ├─ FileSystemSkillBackend → filesystem
                                    └─ DatabaseSkillBackend   → PDO
```

```php
interface SkillBackendInterface
{
    /** Mount completo, validato e stabile per la vita del backend. */
    public function mount(): string;

    /** @return list<string> Identificativi letterali, non URI. */
    public function list(): array;

    /** ID e percorso letterali; @throws RuntimeException */
    public function read(string $identifier, string $path): string;
}
```

Lo storage pubblico conserva `list(): array` e `read($location, $path): string`
di A. Il wrapper acquisisce il mount alla costruzione; costruisce e controlla
le URI skill, decodifica i riferimenti risorsa una sola volta e controlla che
il risultato sia testo UTF-8 supportato.

Il backend espone il mount come metadato di configurazione, ma non riceve URI
skill o URI risorsa nelle letture. Quindi non è completamente ignaro delle URI:
la separazione riguarda il protocollo di lettura, non l'esistenza del mount.

`FileSystemSkillBackend('/app/skills')` deriva internamente il mount file dalla
directory configurata; l'app non fornisce due valori da mantenere coerenti.
`DatabaseSkillBackend('db://team/', $pdo, 'skills')` riceve invece l'etichetta DB
separata dai dati. I vincoli specifici dei costruttori restano nei backend; il
wrapper verifica solo la forma comune del mount. Non seleziona classi in base
allo schema: riceve l'istanza, quindi un nuovo backend non richiede uno switch.

Nel wrapper, la sequenza concettuale è:

```php
$identifier = $codec->identifier($this->mount, $location);
$literalPath = $codec->decodeResourceReference($path);
$content = $this->backend->read($identifier, $literalPath);
// Verifica testo e restituzione; il codec è implementation interna.
```

Il codec qui è illustrativo: non è una nuova dipendenza che l'app deve
configurare. Nel backend DB rimangono normalizzazione lessicale, SQL e confronto
esatto. Nel backend filesystem rimangono snapshot degli ID, mappa verso directory
reali, `realpath()` e confinamento. Il wrapper non semplifica mai `..`.

### Codice applicativo

```php
use NeuronAI\AgentSkills\SkillRepository;
use NeuronAI\AgentSkills\Storage\DatabaseSkillBackend;
use NeuronAI\AgentSkills\Storage\FileSystemSkillBackend;
use NeuronAI\AgentSkills\Storage\MountedSkillStorage;
use NeuronAI\AgentSkills\Tools\SkillToolkit;

function exampleC(PDO $pdo): void
{
    try {
        $repository = new SkillRepository(
            new MountedSkillStorage(new FileSystemSkillBackend('/app/skills')),
            new MountedSkillStorage(
                new DatabaseSkillBackend('db://team/', $pdo, 'skills'),
            ),
        );
        $toolkit = new SkillToolkit($repository);

        $skill = $repository->get('db://team/my%20skill/');
        echo $skill->readResource('references/my%20guide.md'); // Guida DB
        echo $repository->get('file:///app/skills/my%20skill/')
            ->readResource('references/my%20guide.md'); // Guida FS

        $skill->readResource('../secret.md');
    } catch (RuntimeException $exception) {
        echo $exception->getMessage();
    }
}
```

La configurazione espone un livello in più. Il repository e i tool conservano
le operazioni attuali. Per una lettura, il backend PDO riceve precisamente:

```php
$backend->read('my skill', 'references/my guide.md');
```

Non deve sapere se lo spazio arrivava come `%20` o come altra rappresentazione
ammessa: il protocollo pubblico è già stato interpretato.

### Depth, locality e costo

La depth del wrapper consiste nel nascondere il protocollo pubblico agli autori
dei backend. La sua interfaccia non è più grande di quella degli storage attuali.
La locality delle regole URI e UTF-8 diventa un unico flusso comune, non solo
una libreria di funzioni che ciascun adapter deve ricordare di chiamare.

Deletion test: eliminando il wrapper, il protocollo URI e i controlli del testo
ritornano in filesystem e PDO. Il wrapper non è un passaggio vuoto. La seam
interna ha due adapter concreti già necessari, non dipende da un ipotetico terzo
storage. Non tenta di rendere uniforme il significato di `..` nei due backend.

La futura enumerazione richiederebbe un metodo sui backend che restituisce
percorsi letterali e un metodo pubblico che restituisce riferimenti URI.
L'enumerazione e il confinamento restano specifici; la codifica si fa una volta
nel wrapper. È un vantaggio coerente con il lavoro già discusso, ma richiede
comunque modifiche a entrambe le interfacce.

Costo di adozione medio: nuova interfaccia interna, wrapper, migrazione dei due
backend e nuova composizione applicativa. L'interfaccia URI del repository può
restare intatta. I custom storage attuali possono continuare a implementarla;
il nuovo contratto backend offre una strada più semplice per le nuove
implementazioni, senza rendere obbligatori due modelli di test per lo stesso
adapter.

C'è un costo concreto nella diagnostica: il backend conosce l'ID letterale,
mentre l'errore pubblico può dover mostrare la URI. Il wrapper deve aggiungere
contesto ai fallimenti del backend. Se vogliamo conservare parola per parola
i messaggi attuali, serve una categoria interna dell'errore con il percorso
pertinente, da tradurre nel messaggio pubblico; non basta concatenare il testo
di qualunque eccezione. La proposta mantiene `RuntimeException` e gli errori
leggibili dai tool, ma la formulazione letterale va coperta nella migrazione.

### Verifica

Testare il wrapper con i due backend reali, usando filesystem temporaneo e
SQLite. Il test pubblico attraversa la stessa interfaccia usata dal repository:

```php
$storage = new MountedSkillStorage(
    new DatabaseSkillBackend('db://team/', $pdo, 'skills'),
);
$this->assertSame(['db://team/my%20skill/'], $storage->list());
$this->assertSame('Guida DB', $storage->read(
    'db://team/my%20skill/',
    'references/my%20guide.md',
));
$this->expectException(RuntimeException::class);
$storage->read('db://team/my%20skill/', '../secret.md');
```

Si mantiene anche il test di scenario del repository, costruito come in C, per
dimostrare l'assenza di fallback alla skill filesystem. Si portano i test URI,
symlink e PDO su questa composizione. I test degli helper diventati interni si
eliminano solo quando gli stessi casi osservabili sono coperti: niente copie
della stessa suite a ogni livello. Le verifiche del ciclo di vita restano
necessarie, perché il wrapper non deve introdurre nuove cache dei contenuti.

## Confronto

| Criterio | A — Helper negli adapter | B — Oggetti indirizzo | C — Storage comune e backend |
| --- | --- | --- | --- |
| Depth per l'app | Alta, due operazioni | Alta con facade; tipi per uso diretto | Alta, due operazioni; composizione più lunga |
| Chiarezza per chi scrive uno storage | Deve conoscere URI e backend | Deve conoscere due tipi e backend | Legge ID/path letterali; dichiara solo il mount |
| Locality delle regole URI | Algoritmi comuni, invocazioni distribuite | Invarianti nei due oggetti | Algoritmi e sequenza in un solo modulo |
| Seam | Repository ↔ adapter URI | Repository ↔ adapter tipizzato | Repository ↔ storage URI ↔ backend letterale |
| Distinzione path/reference in PHP | Convenzione su stringhe | Garantita dai tipi | Garantita dal livello attraversato, stringhe interne |
| Testabilità | Suite contratto sui due adapter | Stessa suite migrata ai tipi | Suite contratto sul wrapper con backend reali |
| Locality filesystem/SQL | Nel rispettivo adapter | Nel rispettivo adapter | Nel rispettivo backend |
| Adozione | Minima, architettura corrente | Medio-alta, rompe contratto storage | Media, nuova composizione e contratto backend |
| Nuovi concetti | Nessuno rispetto al worktree | Due oggetti immutabili e factory | Distinzione storage pubblico/backend interno |
| Futuro elenco risorse | Ogni adapter produce URI | Ogni adapter produce reference tipizzate | Backend produce nomi, wrapper produce URI |
| Costo principale | Disciplina ripetuta in ogni adapter | Più tipi e metodi da imparare | Livello aggiuntivo e traduzione degli errori |

## Raccomandazione

Per l'obiettivo discusso raccomando **C**: un modulo comune interpreta gli
indirizzi; filesystem e PDO gestiscono i propri dati. È l'unica delle tre che
riduce strutturalmente le regole URI richieste a chi scrive un backend, invece
di rendere più ordinati gli strumenti con cui ogni adapter applica quelle regole.
Questo risponde anche all'intenzione precedente di riutilizzare il lavoro per
altri storage e alla futura necessità di enumerare risorse.

Il vantaggio decisivo non è avere meno metodi in tutto il progetto: è che un
backend legge `('my skill', 'references/my guide.md')` e non deve reinterpretare
il protocollo esterno. Accetto il costo di un wrapper esplicito e di un contratto
interno in più. Le conversioni rimangono necessarie, ma diventano implementation
del modulo che riceve le URI, anziché un catalogo di helper da studiare per
implementare ogni adapter.

Se il criterio prioritario diventasse minimizzare classi e migrazione per
mantenere soltanto questi due piccoli storage, sceglierei **A**. È già valida
e verificata; non occorre cambiarla per una mera preferenza estetica.
Sceglierei **B** se gli indirizzi fossero scambiati e trasformati da molti moduli
PHP indipendenti, rendendo la protezione dei tipi più importante della semplicità
del contratto backend. Il codice attuale non mostra ancora quella necessità.

La scelta tra le tre rimane dell'utente. Questo studio termina con la
raccomandazione: non autorizza l'implementazione di C e non modifica le PR.
