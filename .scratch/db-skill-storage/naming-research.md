# Nomi degli adapter in Neuron AI

Ricerca del 2026-10-06 sul sorgente locale di `neuron-core/neuron-ai` 4.0.1, fissato dal lock al commit `c818e3e80fb04bc5ac0d5743a3937327532a7b1d`. I collegamenti puntano a quel commit, non a un branch variabile.

## Riscontri

| Ambito | Nome pubblico | Riscontro |
| --- | --- | --- |
| Cronologia su database | `SQLMessageStore` | Riceve `PDO` e un nome di tabella configurabile. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Chat/History/SQLMessageStore.php#L55-L63) |
| Persistenza workflow su database | `DatabasePersistence` | Anche questo adapter riceve `PDO` e una tabella configurabile. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Workflow/Persistence/DatabasePersistence.php#L47-L64) |
| Cronologia su file | `FileMessageStore` | Riceve una directory; memorizza un file per thread. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Chat/History/FileMessageStore.php#L38-L65) |
| Persistenza workflow su file | `FilePersistence` | Riceve una directory; memorizza un file per partizione. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Workflow/Persistence/FilePersistence.php#L31-L47) |
| Vector store su file | `FileVectorStore` | Riceve directory e nome del file. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/RAG/VectorStore/FileVectorStore.php#L53-L62) |
| Cache su file | `FileEvaluationCache` | Riceve una directory; memorizza un file per chiave. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Evaluation/Cache/FileEvaluationCache.php#L23-L31) |
| Strumenti per file e directory | `FileSystemToolkit` | Espone strumenti per esplorare, leggere e modificare il filesystem. [Sorgente](https://github.com/neuron-core/neuron-ai/blob/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Tools/Toolkits/FileSystem/FileSystemToolkit.php#L12-L53) |

La ricerca delle dichiarazioni di classi, interfacce e trait nell'intero `src` locale non ha trovato nomi con prefisso `Pdo`, `PDO`, `Db` o `DB`. È un riscontro limitato alla versione esaminata, non una regola dichiarata dal progetto. Le famiglie pertinenti sono consultabili nei sorgenti di [cronologia](https://github.com/neuron-core/neuron-ai/tree/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Chat/History) e [persistenza](https://github.com/neuron-core/neuron-ai/tree/c818e3e80fb04bc5ac0d5743a3937327532a7b1d/src/Workflow/Persistence).

## Interpretazione e proposta iniziale

Non emerge un unico prefisso per il database: Neuron AI usa sia `SQL` sia `Database`, anche quando la connessione è PDO. Per gli adapter di archiviazione su file, il precedente ripetuto è `File`; `FileSystem` compare nei toolkit e negli strumenti per operare su file e directory.

Se l'obiettivo è allineare i nomi ai precedenti più vicini, propongo `SQLSkillStorage` e `FileSkillStorage`, seguendo la coppia `SQLMessageStore` / `FileMessageStore`. `DatabaseSkillStorage` è un'alternativa coerente con `DatabasePersistence`. `PdoSkillStorage` descrive correttamente la dipendenza, ma non riprende i nomi osservati negli adapter generici del progetto.

Questa ricerca non modifica la specifica, gli ADR o il codice. La rinomina dell'adapter filesystem già esistente sarebbe una decisione aggiuntiva rispetto all'introduzione dello storage DB.

## Decisione successiva

L'utente ha scelto i prefissi filesystem e Database: mantenere
`FileSystemSkillStorage` per l'adapter esistente e usare `DatabaseSkillStorage`
per quello nuovo. La specifica e le note del grilling sono state allineate a
questa scelta; PDO rimane la dipendenza tecnica.
