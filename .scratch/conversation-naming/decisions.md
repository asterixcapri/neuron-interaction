# Conversation naming — decisioni dell'intervista

## Confermato

- La classe `NeuronInteraction\Conversation\ConversationRuntime` diventa
  `NeuronInteraction\Conversation`, nel file `src/Conversation.php`.
- Il termine canonico nel glossario del core è **Conversation**.
- Il Command Adapter mantiene `agent()`, `useAgent()`, `session()` e
  `useSession()`, senza esporre un metodo pubblico `conversation()`: i comandi
  non devono ottenere da questo accesso la possibilità di avviare direttamente
  un turno saltando il coordinamento del frontend.
- Introdurre `NeuronInteraction\Command\AbstractCommandAdapter` come base
  opzionale che implementa `CommandAdapterInterface`. Conserva la Conversation
  in una proprietà `protected` e condivide le deleghe di `agent()`, `useAgent()`,
  `session()`, `useSession()` e `sessionStore()`.
- I comandi continuano a dipendere da `CommandAdapterInterface`; resta possibile
  implementare direttamente l'interfaccia senza usare la classe astratta.
- Il cambio di Session deve preservare l'invalidazione della cronologia mostrata
  dalla TUI. La classe astratta non deve impedire questa personalizzazione.
- Rinominare `NeuronTui\Conversation\ConversationInputHandler` in
  `NeuronTui\Input\InputHandler`: gestisce invio, richiamo e modifica dell'input,
  oltre ai tasti di uscita, interruzione e scorrimento della cronologia.
- Mantenere `NeuronTui\View\ConversationView`: il nome descrive ciò che la vista
  presenta. La Conversation appartiene al core; la TUI ne presenta la vista.
- Non mantenere compatibilità con i vecchi nomi: niente alias, classi ponte o
  wrapper di compatibilità. Aggiornare direttamente i consumatori coinvolti.
- Preparare core, TUI e Solaro come un intervento coordinato, con commit e PR
  separati per repository. Verificare i tre progetti insieme sulle revisioni
  aggiornate prima dell'integrazione.
- Preservare il comportamento attuale: coda FIFO, preparazione dei messaggi,
  interruzione, ammissione dei comandi, cronologia e generazione dei titoli.
  Il refactoring cambia i nomi e condivide le deleghe alla Conversation tramite
  la classe astratta, senza modificare queste politiche.
- Richiedere zero vecchi nomi nel codice e nella documentazione corrente.
  Ammettere riferimenti storici in `.scratch/`, `docs/research/` e nelle parti
  storiche degli ADR, inclusa la nota datata sul rename. Usare `git grep` sui
  file versionati e verificare separatamente le occorrenze storiche.
- Spostare anche `tests/Conversation/TurnRunnerTest.php` in `tests/Turn/`,
  insieme al fixture `TurnRunner`, aggiornando namespace e riferimenti.

## Stato

Decisioni chiarite e handoff consolidato in `handoff.md`, confermato dall'utente.
Intervista conclusa; implementazione completata e validata nei tre repository.

Questo documento registra le decisioni confermate durante l'intervista.
