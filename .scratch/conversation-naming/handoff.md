# Handoff — Conversation, Input e Turn

## Obiettivo

La Conversation appartiene a neuron-interaction. Neuron TUI interpreta l'input
della persona e coordina e presenta i turni. Rendere esplicite queste
responsabilità nei nomi, senza cambiare il comportamento.

## Rename nel core

Spostare con `git mv` `src/Conversation/ConversationRuntime.php` in
`src/Conversation.php`. La classe diventa `NeuronInteraction\Conversation`.
Aggiornare test, esempi, documentazione corrente e tutti i consumatori nei tre
repository. Non mantenere alias, classi ponte o wrapper di compatibilità.

## Rename nella TUI

| Oggi | Dopo |
| --- | --- |
| `NeuronTui\Conversation\ConversationInputHandler` | `NeuronTui\Input\InputHandler` |
| `NeuronTui\Conversation\SubmissionParser` | `NeuronTui\Input\SubmissionParser` |
| `NeuronTui\Conversation\CommandInput` | `NeuronTui\Input\CommandInput` |
| `NeuronTui\Conversation\ConversationController` | `NeuronTui\Turn\TurnScheduler` |
| `NeuronTui\Conversation\PendingMessage` | `NeuronTui\Turn\PendingMessage` |
| `NeuronTui\Conversation\TurnRenderer` | `NeuronTui\Turn\TurnRenderer` |

Spostare i file con `git mv`. Aggiornare import, tipi, costruzione, commenti e
riferimenti; rinominare le variabili e proprietà `$controller` in `$scheduler`.
Aggiornare il docblock di TurnScheduler per descrivere accodamento e avvio dei
turni terminali sulla Conversation del core.

Mantenere `NeuronTui\View\ConversationView`: descrive ciò che la vista presenta.
InputHandler comprende anche uscita, interruzione e scorrimento, quindi non si
chiama ComposerInputHandler.

Spostare `SubmissionParserTest.php` in `tests/Input/`, con namespace
`NeuronTui\Tests\Input`. Spostare sia `TurnRunner.php` sia `TurnRunnerTest.php`
in `tests/Turn/`, con namespace `NeuronTui\Tests\Turn`. Aggiornare tutti gli
altri test che referenziano i vecchi nomi, incluso CommandHistoryTest.

## Command Adapter

Mantenere `CommandAdapterInterface` e i metodi `agent()`, `useAgent()`,
`session()` e `useSession()`. Non esporre un metodo pubblico `conversation()`:
consentirebbe ai comandi di chiamare submitMessage() e consumarne lo stream
saltando il coordinamento del frontend. I prompt continuano a entrare tramite
`promptAgent()`.

Introdurre `NeuronInteraction\Command\AbstractCommandAdapter` come base
opzionale che implementa l'interfaccia. Conservare la Conversation in una
proprietà `protected` e condividere le deleghe di `agent()`, `useAgent()`,
`session()`, `useSession()` e `sessionStore()`. Gli adapter possono continuare a
implementare direttamente l'interfaccia; i comandi dipendono dall'interfaccia.

Usare la base per condividere le deleghe negli adapter TUI e backend d'esempio.
Preservare l'invalidazione della cronologia mostrata dalla TUI dopo il cambio di
Session, permettendo la personalizzazione necessaria nella sottoclasse.

## Solaro

Aggiornare `bin/solaro`, che importa ConversationRuntime tramite l'alias
CoreRuntime e costruisce il runtime. Usare la nuova Conversation e allineare i
nomi locali dove descrivono il vecchio runtime.

ModelCommand usa `$adapter->agent()`: questo accesso rimane valido e non
richiede una migrazione a conversation(). Verificare comunque riferimenti,
dipendenze e test dell'intero repository.

## Comportamento da preservare

Coda FIFO, preparazione dei messaggi, interruzione, ammissione dei comandi,
cronologia e generazione dei titoli restano invariati. L'estrazione delle
deleghe nella classe astratta non deve introdurre modifiche a queste politiche.

## Documentazione e riferimenti storici

Aggiornare README e documentazione corrente. Nell'ADR 0003 della TUI aggiornare
la descrizione corrente e aggiungere una nota datata sul rename senza riscrivere
la decisione storica. Non modificare docs/research/claude-code-interface-basics.md
né altro materiale di ricerca storico per eliminare vecchi nomi.

Il controllo dei vecchi nomi deve usare `git grep` sui file versionati. Richiedere
zero occorrenze nel codice e nella documentazione corrente per ConversationRuntime,
NeuronTui\Conversation, ConversationController e ConversationInputHandler.
Ammettere e verificare separatamente le occorrenze in `.scratch/`,
`docs/research/` e nelle parti storiche degli ADR, inclusa la nota sul rename.

## Validazione e integrazione

In ogni repository modificato eseguire `composer cs:fix`, `composer stan` e
`vendor/bin/phpunit`. Prima del push eseguire anche `composer test` e `composer cs`.
Verificare gli esempi interessati e i consumatori sulle revisioni aggiornate.

Preparare le modifiche nei tre repository come un intervento coordinato, con
commit e PR separati. Aggiornare le dipendenze e i lock file pertinenti alle
revisioni che introducono i nuovi nomi e verificare insieme core, TUI e Solaro
prima dell'integrazione. Nessuno strato di compatibilità con i vecchi nomi.

## Stato

Handoff confermato dall'utente e implementato nei tre repository.

Validazione locale: PHPStan e formattazione superati; 231 test core, 269 test TUI,
57 test Solaro e 12 test degli esempi TUI superati. Il nuovo test TUI copre il
refresh della cronologia quando si riseleziona la stessa Session. Vecchi nomi
presenti soltanto nei riferimenti storici autorizzati; ricerca storica invariata.
