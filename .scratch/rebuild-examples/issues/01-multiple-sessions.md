# 01 — Più Session

Status: needs-triage
Implementation: complete

## Obiettivo

Creare due conversazioni, elencare le Session e passare da una all’altra mantenendo i rispettivi contesti.

## Vincoli

Seguire le [decisioni approvate](../spec.md). Progettare e implementare questo
esempio singolarmente quando richiesto dall’utente; la pulizia attuale non
ne autorizza l’avvio. Definire il flusso concreto prima di implementarlo.

## Verifica

L’esempio deve rendere visibile il vantaggio descritto. Se interroga l’Agent,
usare OpenAI reale e mostrare lo streaming. Aggiornare le istruzioni di esecuzione
insieme all’esempio.

## Implementazione

Autorizzata dall’utente con «facciamo solo il primo».

`examples/bin/01-multiple-sessions.php` configura DemoAgent con AIProviderFactory,
copiati dagli esempi di neuron-tui e adattati al namespace NeuronInteractionDemo.
Seleziona OpenAI e crea due Session con destinazioni diverse, elenca le Session salvate e torna a
ciascuna tramite la sua chiave. I messaggi vengono conservati da FileStorage;
la stessa domanda riceve una risposta coerente con il contesto della Session.
Ogni risposta è mostrata in streaming, senza foreach vuoti.

## Verifica eseguita

- OpenAI reale, modello gpt-5.4-nano: ritorno a Lisbon e Kyoto, quattro messaggi
  salvati per Session.
- composer cs:fix applicato.
- composer stan: nessun errore.
- vendor/bin/phpunit: 228 test passati.

## Dipendenze degli esempi

Gli esempi hanno ora un progetto Composer indipendente in examples/. Dotenv
appartiene solo a quel progetto. Il repository path collega la libreria al
checkout padre con symlink; l’esempio carica examples/vendor/autoload.php.

Installazione: composer --working-dir=examples install.
Esecuzione: composer --working-dir=examples sessions.
Analisi degli esempi: composer --working-dir=examples stan.

Verificata nuovamente l’esecuzione con OpenAI reale e l’analisi statica dei due
progetti separati. I 228 test della libreria passano.

## Configurazione condivisa degli esempi

Su richiesta dell’utente, AIProviderFactory e DemoAgent sono stati copiati da
neuron-tui/examples/src a examples/src. Il namespace è NeuronInteractionDemo,
registrato nel progetto Composer degli esempi. Anche amphp/http-client appartiene
solo alle dipendenze degli esempi. L’esempio continua a usare OpenAI.

Esecuzione reale verificata: Lisbon e Kyoto mantengono i rispettivi contesti.
PHPStan passa sia nella libreria sia negli esempi; 228 test della libreria passano.
