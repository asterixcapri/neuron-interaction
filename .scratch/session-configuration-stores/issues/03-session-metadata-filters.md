# 03: Metadata persistenti e filtri delle Session

**What to build:** Una Host Application associa attributi alle Session e cerca le conversazioni dell'utente corrente combinando più filtri. Aggiornare i metadata non perde messaggi e ricevere nuovi messaggi non perde metadata.

**Blocked by:** 01 — SessionStore per utente e persistenza della History.

**Status:** ready-for-agent

- [ ] SessionStore.create accetta metadata iniziali facoltativi e li rende disponibili subito dopo la creazione e dopo riapertura.
- [ ] Session espone getMetadata, setMetadata e removeMetadata. Le modifiche ai metadata si persistono automaticamente, preservando History e proprietario.
- [ ] I metadata applicativi sono coppie di nomi camelCase e valori stringa, coerenti con Storage. Il proprietario e le informazioni di utilizzo gestite dalla libreria non sono modificabili tramite i setter dei metadata applicativi.
- [ ] SessionStore.summaries accetta un filtro facoltativo: confronti per uguaglianza esatta fra stringhe, condizioni in AND, chiavi mancanti non corrispondenti e attributi extra ignorati.
- [ ] I filtri sono sempre applicati nell'ambito dell'utente dello Store e non possono sostituire tale ambito. Il filtro vuoto conserva il comportamento di elenco del ticket 01.
- [ ] Titoli, esclusione delle Session vuote e ordinamento per ultimo utilizzo mantengono il comportamento esistente anche nei risultati filtrati.
- [ ] StorageInterface.entries e gli adapter in memoria e su file supportano il filtro per metadata. Il contratto non impone query su JSON annidato o una particolare disposizione dei file.
- [ ] Test attraverso SessionStore verificano creazione con metadata, aggiunta, modifica, rimozione, filtri multipli, valori simili ma non identici, attributi mancanti e isolamento fra utenti.
- [ ] Test di riapertura verificano che i metadata sopravvivano alle operazioni della History e che i messaggi sopravvivano alle modifiche ai metadata, con nuove istanze e Storage su file.
- [ ] I test di contratto degli adapter verificano solo le nuove garanzie dei filtri senza duplicare tutti gli scenari applicativi. PHPUnit e PHPStan di neuron-interaction passano.

Non dipende da ConfigurationStore: il contenuto delle Configuration resta
distinto dai metadata di ricerca delle Session. Non introdurre un linguaggio
di query o nuove politiche applicative di organizzazione delle conversazioni.
