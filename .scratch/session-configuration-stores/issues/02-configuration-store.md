# 02: ConfigurationStore per utente

**What to build:** Una Host Application crea configurazioni personali con chiavi scelte, le carica, modifica più valori in memoria e le salva esplicitamente. ConfigurationStore usa lo Storage comune e permette a utenti diversi di avere configurazioni con la stessa chiave senza interferenze nel normale utilizzo.

**Blocked by:** None (can start immediately).

**Status:** completed

- [x] ConfigurationStore riceve StorageInterface e userId obbligatorio alla costruzione. Ogni operazione normale usa quell'utente senza richiederlo nuovamente.
- [x] create accetta chiave e valori iniziali facoltativi e persiste subito il documento. Una chiave già esistente per quell'utente non viene sovrascritta. Utenti diversi possono usare la stessa chiave logica.
- [x] read restituisce Configuration o null; delete elimina una configurazione presente ed è un no-op se assente. Le normali operazioni di utenti diversi rimangono indipendenti.
- [x] Configuration espone chiave, proprietario, has, get con default facoltativo, set, remove e all. Una voce presente con valore null è distinguibile da una voce assente.
- [x] Il contenuto è una mappa di valori JSON liberi, inclusi scalari, null, liste e strutture annidate. Non sono imposti campi di modello, provider o Agent e non vengono serializzati automaticamente oggetti PHP arbitrari.
- [x] set aggiunge o sostituisce una voce e remove la elimina solo in memoria. Prima di save una nuova lettura vede ancora i dati persistiti precedenti.
- [x] save accetta Configuration e persiste insieme tutti i valori modificati. Nel normale flusso di lettura-modifica-salvataggio, aggiornare model mantiene le altre opzioni e una nuova istanza può rileggere il risultato.
- [x] Non vengono introdotti autosave, sostituzione di save con write per chiave e valori, selezione automatica della configurazione attiva o sostituzione automatica dell'Agent in memoria.
- [x] Storage supporta la creazione con chiave fornita, mantenendo quella generata quando omessa. Una creazione concorrente della stessa chiave non sovrascrive il documento già creato. Adattare entrambe le implementazioni e i call site necessari senza aggiungere gestione dei conflitti sugli aggiornamenti.
- [x] Test pubblici verificano persistenza dopo riapertura, isolamento degli utenti, chiavi duplicate, letture assenti, eliminazione, round trip JSON, valori non validi, modifiche non salvate e salvataggio esplicito, su Storage in memoria e su file.
- [x] Gli esempi illustrano creazione, lettura, aggiornamento di model e save. PHPUnit e PHPStan di neuron-interaction passano senza dipendere dal ticket 01.

La specifica non approva né il rifiuto obbligatorio di un oggetto proveniente
da uno Store di un altro utente, né l'obbligo di fallire salvando una
Configuration eliminata dopo la lettura. Non introdurre test che impongano
quelle regole come concordate. Se l'implementazione richiede di fissare tali
semantiche, chiarirle prima di esporle come garanzie; mantenere fermo il
contratto create/read/modifica/save approvato.

Questo ticket non richiede il nuovo SessionStore. Le modifiche allo Storage
devono mantenere utilizzabili i consumatori presenti nella revisione di lavoro.


## Validation

- Configuration and Storage public tests: 53 tests, 158 assertions pass,
  including eight competing FileStorage creation processes.
- Full library suite: `php -d allow_url_fopen=0 vendor/bin/phpunit` passes
  (133 tests, 456 assertions). The flag avoids the pre-existing remote image
  fixture pending the Session ticket's offline fixture update.
- `composer stan`: passes.
- `php examples/configuration.php`: passes and retains provider/tools while
  saving the updated model.
- Storage also implements exact AND metadata filtering for ticket 03's
  SessionStore consumer.
