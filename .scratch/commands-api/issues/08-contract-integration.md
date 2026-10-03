# 08: Chiudere la migrazione e validare i tre progetti insieme

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction, neuron-tui, solaro

**What to build:** La revisione finale dei tre repository espone soltanto il nuovo contratto e permette di usare gli esempi e solaro con dipendenze compatibili e verifiche complete superate.

**Blocked by:** 06, 07

Status: implemented

- [x] Non restano usi operativi di submitMessage, adapter dei Command, afterExecution, vecchio CommandExecution, generics di output, vecchia Selection o ConcurrentCommandInterface.
- [x] Le eventuali definizioni temporaneamente rimaste vengono eliminate; non si consegnano bridge o percorsi pubblici legacy.
- [x] Tutti gli esempi e helper usano submitInput e conservano un vantaggio per esempio; comandi e scelte funzionano senza adapter.
- [x] Documentazione e guida di migrazione dei tre progetti descrivono input, eventi, eccezioni, richieste, admission e Selection senza stato pendente; le revisioni degli ADR sono coerenti.
- [x] Le dipendenze locali risolvono revisioni compatibili e i test congiunti coprono messaggi, help, unknown/refused, model, clear/resume, review di esempio, skill, picker ed exit.
- [x] Vengono controllati errori, ordine delle richieste, response stop, approval, risorse e assenza di doppia preparazione, esecuzione o visualizzazione introdotta dalla migrazione.
- [x] Ogni repository supera formatter, PHPStan e suite rilevanti; prima di eventuale push si eseguono anche suite complete e stile. Nessun push o rilascio è incluso.
- [x] Le modifiche precedenti dell’utente sono integrate senza sovrascriverle; i risultati e gli eventuali limiti sono registrati.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments


Implementazione, integrazione e revisione completate il 2026-10-03.
Le PR coordinate consegnano il risultato per la revisione umana.

Validazione locale con PHP8.5.8: core237 test/2327 asserzioni, TUI262/1226,
Solaro61/254; esempi TUI12/58. Formatter, PHPStan e check stile superati nei tre
repository; PHPStan degli esempi core e TUI superato. Gli esempi senza provider
00-input e08-portable-selection eseguiti; gli esempi con provider remoto sono
verificati staticamente, senza chiamate remote. La CI conserva PHP8.4 e8.5.

Eliminati adapter, risultato tecnico, marker e Selection legacy; gli scenari
ancora validi dei vecchi test sono coperti dai test pubblici Conversation. Resume
normalizza i propri argomenti per conservare l'uso terminale con spazi, senza
modificare i valori opachi generali. Il test Solaro verifica risposta e History
persistita della skill, senza pretendere un preview del prompt generato.

Le librerie mantengono composer.lock locale ignorato; versioni path1.x-dev
esplicite e checkout CI del core compatibile coordinano la revisione. Solaro
registra nel proprio lock le revisioni compatibili. Le bozze utente estranee e
gli ADR dello storage non sono inclusi.

Autorizzazione successiva dell'utente: «i push li puoi fare» consente push e PR
remote coordinate dopo la validazione, superando l'esclusione storica nello spec.
Questo ticket non esegue push; pubblicazione delle PR e review sono curate nel
workflow di integrazione. Nessun rilascio è richiesto.

Revisione finale: nessuna violazione documentata degli standard; una duplicazione
di completamento e una regressione P2 nella presentazione di una risposta senza
chunk sono risolte nel commit TUI `7ab51d7`, integrato in `17ee7be`. Il test pubblico
con risposta visibile seguita da risposta vuota preserva il primo messaggio; i
Command senza prompt restano inerti. Verifica puntuale della correzione superata.

Suite finali: core 237/2327, TUI 262/1226, Solaro 61/254; esempi TUI 12/58.
La CI dei branch core e TUI supera PHP 8.4 e 8.5. PR coordinate: core #8, TUI #25,
Solaro #11. Nessun rilascio o merge nelle linee di destinazione eseguito.
