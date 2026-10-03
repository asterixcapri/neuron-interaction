# 08: Chiudere la migrazione e validare i tre progetti insieme

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction, neuron-tui, solaro

**What to build:** La revisione finale dei tre repository espone soltanto il nuovo contratto e permette di usare gli esempi e solaro con dipendenze compatibili e verifiche complete superate.

**Blocked by:** 06, 07

Status: ready-for-agent

- [ ] Non restano usi operativi di submitMessage, adapter dei Command, afterExecution, vecchio CommandExecution, generics di output, vecchia Selection o ConcurrentCommandInterface.
- [ ] Le eventuali definizioni temporaneamente rimaste vengono eliminate; non si consegnano bridge o percorsi pubblici legacy.
- [ ] Tutti gli esempi e helper usano submitInput e conservano un vantaggio per esempio; comandi e scelte funzionano senza adapter.
- [ ] Documentazione e guida di migrazione dei tre progetti descrivono input, eventi, eccezioni, richieste, admission e Selection senza stato pendente; le revisioni degli ADR sono coerenti.
- [ ] Le dipendenze locali risolvono revisioni compatibili e i test congiunti coprono messaggi, help, unknown/refused, model, clear/resume, review di esempio, skill, picker ed exit.
- [ ] Vengono controllati errori, ordine delle richieste, response stop, approval, risorse e assenza di doppia preparazione, esecuzione o visualizzazione introdotta dalla migrazione.
- [ ] Ogni repository supera formatter, PHPStan e suite rilevanti; prima di eventuale push si eseguono anche suite complete e stile. Nessun push o rilascio è incluso.
- [ ] Le modifiche precedenti dell’utente sono integrate senza sovrascriverle; i risultati e gli eventuali limiti sono registrati.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

