# 02: Completare scelte nel terminale e tra richieste HTTP

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction

**What to build:** Un Command chiede una SelectionRequest e una invocazione successiva riceve il valore scelto, anche con una nuova Conversation, senza stato pendente nel core.

**Blocked by:** 01

Status: ready-for-agent

- [x] SelectionRequest contiene command, prompt, opzioni ordinate non vuote e descrizione facoltativa; SelectionOption conserva valore, etichetta e descrizione.
- [x] Conversation emette le richieste in ordine senza memorizzare una Selection, callback o continuazione.
- [x] La risposta CommandInput inoltra il valore opaco al Command, anche se contiene slash o non figura tra le opzioni; nessuna validazione automatica del valore.
- [x] Il Command può chiedere un altro passo o riproporre una scelta; sono ammesse più richieste e nessuna viene annullata da una politica del core.
- [x] Un test con due Conversation simula due richieste HTTP: la seconda riceve command e value con Session e store corretti, senza recuperare la Selection.
- [x] Un esempio dedicato mostra una scelta reale e documenta come inviare il CommandInput; annullare nella Host Application non richiede alcun input al core.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `dae2442` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
