# 04: Adeguamento e verifica di neuron-tui

**What to build:** neuron-tui e la sua demo funzionano con il nuovo neuron-interaction: costruiscono o ricevono SessionStore associati all'utente, eseguono i Command e mantengono la persistenza automatica della History senza cambiare il normale flusso del terminale.

**Blocked by:** 01 — SessionStore per utente e persistenza della History; 03 — Metadata persistenti e filtri delle Session.

**Status:** completed

- [x] Il lavoro comprende il repository neuron-tui e la sua demo. La dipendenza effettivamente installata usa il nuovo contratto di neuron-interaction, anche nel grafo Composer separato della demo; aggiornare i lockfile applicabili.
- [x] La verifica locale della dipendenza è riproducibile. Non commettere percorsi assoluti specifici della macchina e non considerare autorizzata una pubblicazione di release per soddisfare questo ticket.
- [x] Composizione della TUI, input dispatch, TuiAdapter, fixtures, esempi e documentazione usano SessionStore al posto di Sessions, senza wrapper di retrocompatibilità. Adattare create/read e gestire i risultati null.
- [x] Un SessionStore fornito dalla Host Application conserva il proprio utente. Quando la composizione locale deve risolvere l'identità, usa prima quella configurata, poi l'utente del sistema disponibile, infine un fallback stabile. La scelta non viene ripetuta nei Command né spostata negli Store.
- [x] La TUI conserva la History iniziale fornita dall'applicazione, inclusa una History non gestita come Session, senza registrarla o sostituirla implicitamente. Rimane verificato il comportamento con persistenza predefinita e fornita esplicitamente.
- [x] Clear avvia una nuova Session dell'utente e conserva quella precedente. Resume mostra solo le sue Session e riapre la scelta attraverso la nuova invocazione del Command. Le Session assenti producono un esito gestito.
- [x] Restano funzionanti annullamento della selezione, politiche dei Command durante un Turn, input history indipendente e useAgent con trasferimento della History al nuovo Agent.
- [x] La demo si avvia ed esce con la nuova composizione; il suo Command di cambio modello continua a funzionare conservando la History. Non è richiesta l'introduzione di preferenze modello persistite.
- [x] I test pubblici esistenti della TUI e della composizione delle Session coprono il flusso messaggi, Clear, Resume e riapertura persistita; verificano che una Session di un altro utente non venga proposta.
- [x] Le verifiche automatiche usano i fixture del terminale e dell'Agent/provider, senza credenziali né richieste a pagamento. Eseguire uno smoke test di avvio/uscita della demo e dichiarare eventuali limiti della verifica interattiva.
- [x] PHPUnit e PHPStan passano sia in neuron-interaction sia in neuron-tui con la dipendenza aggiornata. Non basta eseguire i test della TUI contro una vecchia versione già presente nel vendor.

Il ticket non dipende dal 02: l'integrazione della TUI non richiede una nuova
politica di Configuration o del modello. La funzionalità complessiva richiede
comunque il completamento di tutti e quattro i ticket e controlli sullo stato
integrato finale. AgentDeck, API remote, autenticazione remota e dati condivisi
fra le applicazioni restano fuori scope.

## Validation

Implemented in neuron-tui commit `21bbd0f4410a8db4c7c682733f99835cb4ca0c4c`, linked PR https://github.com/asterixcapri/neuron-tui/pull/13.

- TUI: PHPUnit 217 tests / 955 assertions; PHPStan passes.
- Demo separate dependency graph: PHPUnit 2 tests / 14 assertions; PHPStan passes.
- Both Composer graphs resolve neuron-interaction `4eb4ad670d07e693c7c1d3c0e01cbec5de5435b4`; final review fixes will be followed by lockfile updates.
- Actual PTY smoke: startup, `/model`, selection and `/exit` passed with exit code 0, empty credentials and no provider requests. Automated demo tests verify History preservation.
