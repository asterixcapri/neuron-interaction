# Command API: ticket di implementazione

Suddivisione approvata dall’utente il 2026-10-03.
Gli otto ticket sono pubblicati con stato ready-for-agent e sostituiscono le
vecchie bozze. La nota nello spec sulle issue ancora da riscrivere descrive
la fase precedente; questo indice registra il completamento di to-tickets.

Spec: [Command e input gestiti da Conversation](spec.md).

## Ticket

1. [Inviare messaggi e ricevere notifiche attraverso Conversation](issues/01-input-notifications.md)
2. [Completare scelte nel terminale e tra richieste HTTP](issues/02-portable-selection.md)
3. [Eseguire i prompt dei Command nello stesso stream](issues/03-command-prompts.md)
4. [Cambiare Session e Agent con eventi osservabili](issues/04-state-commands.md)
5. [Lasciare all’host ammissione, help e uscita](issues/05-host-admission-exit.md)
6. [Usare input ed eventi della Conversation nella TUI](issues/06-tui-integration.md)
7. [Conservare modello e skill di solaro con il nuovo context](issues/07-solaro-commands.md)
8. [Chiudere la migrazione e validare i tre progetti insieme](issues/08-contract-integration.md)

## Coordinamento

La prima frontier contiene 01. Dopo 01 sono disponibili 02, 03 e 05; 04 richiede
02. La TUI (06) richiede 02, 03, 04 e 05. Solaro (07) richiede 03, 04 e 05,
senza dipendere dalla fine di 06. La verifica conclusiva (08) richiede 06 e 07.

La migrazione incompatibile usa branch di integrazione coordinati nei tre
repository. Le slice hanno test mirati; le suite complete dei tre progetti
devono essere verdi alla chiusura di 08. Non si pubblicano revisioni intermedie
incompatibili e non si introducono bridge pubblici fuori dallo spec.

La pubblicazione dei ticket non avvia implementazione, commit, PR o push.

## Stato dell'implementazione

Gli otto ticket sono implementati e la validazione congiunta è completata:
core 237 test/2327 asserzioni, TUI 262/1226, Solaro 61/254 ed esempi TUI 12/58.
Formatter, analisi statica e stile sono verdi. La revisione di standard e spec
è completata e tutti i rilievi sono risolti. I dettagli sono nel ticket 08.

PR coordinate per la revisione umana: [core #8](https://github.com/asterixcapri/neuron-interaction/pull/8),
[TUI #25](https://github.com/asterixcapri/neuron-tui/pull/25) e
[Solaro #11](https://github.com/asterixcapri/solaro/pull/11).

L'utente ha successivamente autorizzato i push («i push li puoi fare»). La nota
precedente sulla pubblicazione dei ticket descrive la fase di pianificazione,
non limita il workflow di implementazione e PR ora autorizzato.
