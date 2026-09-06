# 03: Metadata persistenti e filtri delle Session

**What to build:** Una Host Application associa attributi alle Session e cerca le conversazioni dell'utente corrente combinando più filtri. Aggiornare i metadata non perde messaggi e ricevere nuovi messaggi non perde metadata.

**Blocked by:** 01 — SessionStore per utente e persistenza della History.

**Status:** completed

- [x] SessionStore.create accetta metadata iniziali facoltativi e li rende disponibili subito dopo la creazione e dopo riapertura.
- [x] Session espone getMetadata, setMetadata e removeMetadata. Le modifiche ai metadata si persistono automaticamente, preservando History e proprietario.
- [x] I metadata applicativi sono coppie di nomi camelCase e valori stringa, coerenti con Storage. Il proprietario e le informazioni di utilizzo gestite dalla libreria non sono modificabili tramite i setter dei metadata applicativi.
- [x] SessionStore.summaries accetta un filtro facoltativo: confronti per uguaglianza esatta fra stringhe, condizioni in AND, chiavi mancanti non corrispondenti e attributi extra ignorati.
- [x] I filtri sono sempre applicati nell'ambito dell'utente dello Store e non possono sostituire tale ambito. Il filtro vuoto conserva il comportamento di elenco del ticket 01.
- [x] Titoli, esclusione delle Session vuote e ordinamento per ultimo utilizzo mantengono il comportamento esistente anche nei risultati filtrati.
- [x] StorageInterface.entries e gli adapter in memoria e su file supportano il filtro per metadata. Il contratto non impone query su JSON annidato o una particolare disposizione dei file.
- [x] Test attraverso SessionStore verificano creazione con metadata, aggiunta, modifica, rimozione, filtri multipli, valori simili ma non identici, attributi mancanti e isolamento fra utenti.
- [x] Test di riapertura verificano che i metadata sopravvivano alle operazioni della History e che i messaggi sopravvivano alle modifiche ai metadata, con nuove istanze e Storage su file.
- [x] I test di contratto degli adapter verificano solo le nuove garanzie dei filtri senza duplicare tutti gli scenari applicativi. PHPUnit e PHPStan di neuron-interaction passano.

Non dipende da ConfigurationStore: il contenuto delle Configuration resta
distinto dai metadata di ricerca delle Session. Non introdurre un linguaggio
di query o nuove politiche applicative di organizzazione delle conversazioni.

Validazione: `composer test` passa (146 test, 550 asserzioni); `composer stan`
passa. I test pubblici coprono entrambi gli adapter e la riapertura tramite
nuovi FileStorage. I test di contratto Storage per i filtri sono integrati dal
ticket 02 (commit aa6b009). `git diff --check` non segnala problemi.
