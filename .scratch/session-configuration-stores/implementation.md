# Implementazione e verifica

Implementazione completa nei due repository:

- neuron-interaction: https://github.com/asterixcapri/neuron-interaction/pull/3
- neuron-tui e demo: https://github.com/asterixcapri/neuron-tui/pull/13

Tutti e quattro i ticket sono implementati. Le modifiche sostituiscono Sessions con SessionStore, aggiungono ConfigurationStore e metadata filtrabili, e adeguano composizione, Command, esempi e documentazione TUI.

## Review: Standards

- Corretto il commento TUI che descriveva il Picker come contenitore di SessionStore.
- Rimossa la duplicazione nella preparazione dei file temporanei di FileStorage; creazione atomica senza sovrascrittura e sostituzione restano distinte.
- Allineate le note correnti degli ADR al nuovo contratto, preservando il testo storico.

## Review: Spec

- Configuration copia ricorsivamente per valore gli array validati, impedendo che riferimenti esterni introducano oggetti PHP dopo la validazione.
- InMemoryStorage conserva una copia dei metadata indipendente dai riferimenti esterni, coerentemente con FileStorage.
- Test pubblici di regressione coprono entrambi gli adapter.
- Non sono state introdotte regole sui due casi di salvataggio esplicitamente esclusi dalla spec.

Correzioni: library `54d20ad`, TUI `f76bc6d`. Nessun rilievo lasciato aperto.

## Verifiche

- Library integrata dopo le correzioni: PHPUnit **150 test / 572 asserzioni**, PHPStan senza errori.
- TUI integrata: PHPUnit **217 test / 955 asserzioni**, PHPStan senza errori.
- Demo, nel proprio grafo Composer: PHPUnit **2 test / 14 asserzioni**, PHPStan senza errori.
- TUI e demo hanno verificato la libreria `4eb4ad6`; la PR TUI aggiorna entrambi i lockfile alla revisione definitiva dopo le correzioni e registra la verifica finale.
- Smoke effettivo su PTY: avvio demo, `/model`, selezione e `/exit`, codice 0. Nessuna credenziale né richiesta a provider. I test della demo verificano che il cambio modello preservi la History e la sua persistenza.
- I test mantengono copertura di annullamento selezione, policy durante un Turn, useAgent e Input history indipendente.

La pubblicazione di release e l'integrazione AgentDeck restano fuori scope.
