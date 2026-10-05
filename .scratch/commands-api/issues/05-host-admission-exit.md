# 05: Lasciare all’host ammissione, help e uscita

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction

**What to build:** La Host Application decide quali Command eseguire; Help legge il catalogo e Leave produce ExitRequest senza terminare Conversation.

**Blocked by:** 01

Status: ready-for-agent

- [x] La closure facoltativa di admission riceve il Command e viene valutata prima di ogni invocazione; senza closure il Command è ammesso.
- [x] Il rifiuto produce Notification Warning senza eseguire il Command; un’eccezione della closure propaga senza effetti del Command.
- [x] HelpCommand usa l’elenco consultabile del context e notify per descrivere i Command registrati.
- [x] ExitCommand usa requestExit e produce ExitRequest in ordine, senza terminare Conversation, interrompere l’Agent o annullare altre richieste.
- [x] I test dimostrano che un host può ignorare ExitRequest e continuare a sottoporre input e che l’ammissione resta indipendente dalla classe condivisa del Command.
- [x] Il contratto finale non richiede ConcurrentCommandInterface, request generico, CommandFinished o eventi dedicati ai fallimenti.
- [x] Un consumer terminale dimostra l’uscita gestita dall’host e documenta la differenza rispetto all’interruzione della risposta.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `db77a4c` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
