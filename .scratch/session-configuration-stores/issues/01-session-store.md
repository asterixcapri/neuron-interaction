# 01: SessionStore per utente e persistenza della History

**What to build:** Una Host Application costruisce SessionStore con Storage e userId una sola volta e gestisce le conversazioni di quell'utente. La Session rimane direttamente utilizzabile come Chat History Neuron e persiste automaticamente i messaggi. I Command della libreria usano il nuovo contratto senza gestire l'identità.

**Blocked by:** None (can start immediately).

**Status:** completed

- [x] SessionStore sostituisce Sessions senza alias, wrapper di compatibilità o migrazione dei dati precedenti. Il costruttore riceve StorageInterface e userId obbligatorio; nessun fallback viene scelto dalla libreria.
- [x] create genera una chiave e persiste subito una Session vuota con proprietario esplicito; read restituisce una Session o null; delete elimina se presente. Le operazioni e summaries sono limitate all'utente dello Store.
- [x] Session espone chiave e proprietario. I dati senza proprietario non vengono assegnati implicitamente a un utente. La rappresentazione interna dell'identità non impone ai chiamanti nomi fisici di Storage.
- [x] summaries conserva esclusione delle conversazioni vuote, titolo derivato dal contenuto dell'utente e ordinamento per ultimo utilizzo. L'aggiunta dei filtri applicativi è completata dal ticket 03.
- [x] Session resta una Chat History Neuron: aggiunta, cancellazione e trimming dei messaggi si persistono automaticamente, senza save esplicito del chiamante. Messaggi, reasoning, tool e contenuti supportati sopravvivono alla riapertura.
- [x] Il salvataggio della History preserva il proprietario e qualsiasi metadata già associato al documento, aggiornando correttamente le informazioni di utilizzo.
- [x] CommandAdapterInterface e i Command della libreria ricevono SessionStore già associato all'utente. Non viene aggiunto userId all'interfaccia dell'adapter. I call site, gli esempi e la documentazione d'uso della libreria adottano il nuovo contratto.
- [x] Clear crea una nuova Session senza eliminare la precedente. Resume mantiene selezione e nuova invocazione con la chiave scelta; una Session assente produce un esito gestito, senza creazione implicita.
- [x] Test attraverso SessionStore e le operazioni pubbliche della History verificano creazione, riapertura, cancellazione, isolamento utenti e round trip dei messaggi con Storage in memoria e su file, anche con nuove istanze.
- [x] I test esistenti di SessionSummary, Command ed esempio backend sono adeguati; PHPUnit e PHPStan di neuron-interaction passano. Non servono credenziali o chiamate a un LLM.

Il ticket modifica neuron-interaction. L'adeguamento del consumatore neuron-tui
è esplicitamente nel ticket 04: non introdurre retrocompatibilità per coprire
temporaneamente quel passaggio. La funzionalità complessiva non è completa
finché non è verificato anche il consumatore.

Validation: `composer test` passes (124 tests, 404 assertions); `composer stan` passes.
Public Store tests cover both adapters, fresh file-backed reads, rich message
round trips, native trimming and clearing, user isolation and deletion. Image
fixtures use embedded PNG content so the suite requires no network access.
