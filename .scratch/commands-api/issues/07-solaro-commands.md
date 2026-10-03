# 07: Conservare modello e skill di solaro con il nuovo context

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: solaro

**What to build:** Solaro mantiene selezione/refresh del modello, preferenze, espansione e invocazione delle skill usando i nuovi Command; il registro riceve nomi univoci.

**Blocked by:** 03, 04, 05

Status: ready-for-agent

- [x] ModelCommand richiede SelectionRequest e usa un solo notify con livello; conserva catalogo, refresh e preferenze e usa useAgent per rendere osservabile il cambio provider.
- [x] SkillCommand conserva istruzioni, argomenti, metadati ed errori di espansione e usa promptAgent per il messaggio espanso.
- [x] ReviewCommand, dove mantenuto come esempio, passa al context senza aggiungere nuove politiche o funzionalità.
- [x] Solaro risolve le collisioni di nomi prima del registro e conserva la priorità delle skill sui built-in senza dipendere dai duplicati.
- [x] I test applicativi verificano gli effetti attraverso Conversation e sostituiscono i mock dell’adapter; non introducono nuovi adapter di test.
- [x] Composizione, documentazione e decisione architetturale sulle skill riflettono la nuova risoluzione delle collisioni.
- [x] La validazione di questo ticket usa il core aggiornato; lo smoke test dell’intera TUI viene completato nel ticket di integrazione congiunta.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `a789238` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
