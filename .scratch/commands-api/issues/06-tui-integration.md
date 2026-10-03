# 06: Usare input ed eventi della Conversation nella TUI

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-tui

**What to build:** La TUI completa messaggi, Command, picker e uscita tramite la propria Conversation, mantenendo interazione, coda e interruzione esistenti.

**Blocked by:** 02, 03, 04, 05

Status: ready-for-agent

- [x] Tui costruisce ancora la Conversation internamente e le passa registro, configurazione e politica di admission; gli input passano da submitInput.
- [x] Notifiche, SelectionRequest, ExitRequest e cambi di Agent/Session vengono presentati o gestiti senza TuiCommandAdapter, dispatch diretto o hook di completamento.
- [x] Il picker sottopone CommandInput con command e value; Escape chiude il picker senza chiamare Conversation; etichette, descrizioni e valori opachi sono preservati.
- [x] Mentre lavora, la TUI conserva help e uscita disponibili e rifiuta i Command ordinari tramite propria politica, senza ConcurrentCommandInterface.
- [x] I prompt dei Command vengono eseguiti nel relativo stream: nessuna seconda coda, doppia preparazione o reinvio al motore.
- [x] Sono mantenuti coda umana, draft, InputHistory, proiezione History, allegati, approval, title scheduling, response stop e rilascio delle risorse, senza introdurre eventi dei prompt.
- [x] I test esistenti delle interazioni e la documentazione TUI vengono adattati; si registrano esplicitamente le revisioni delle decisioni architetturali coinvolte.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `7586f26` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
