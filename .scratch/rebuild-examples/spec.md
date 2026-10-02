# Ricostruire gli esempi di Neuron Interaction

## Obiettivo

Dimostrare allo sviluppatore i vantaggi concreti di Neuron Interaction rispetto
all’uso del solo Neuron AI, con esempi dal più semplice al più completo.

## Decisioni approvate

- Iniziare dalla gestione di più Session. Un singolo scambio con un Agent non
  rende abbastanza evidente il valore aggiunto della libreria.
- Usare un Agent reale e OpenAI come Provider, prendendo spunto dagli esempi di
  neuron-tui per la configurazione. Per le prove locali usare il `.env` copiato
  da neuron-tui/examples; non pubblicare le credenziali.
- Organizzare examples/ come Host Application con il proprio composer.json e
  vendor/. Le dipendenze necessarie solo agli esempi, come Symfony Dotenv, non
  appartengono al composer.json della libreria.
- Mostrare sempre la risposta in streaming negli esempi che interrogano l’Agent.
  Non usare foreach con corpo vuoto.
- Concentrarsi su un vantaggio per esempio, senza introdurre callback, code di
  prompt o altri meccanismi che non servano a dimostrare quel vantaggio.
- I primi cinque esempi sono il percorso principale; Input history e Preferenze
  sono moduli utilizzabili anche indipendentemente.
- Non aggiungere per ora un esempio finale che mette insieme tutto.

## Ordine di implementazione

1. [Più Session](issues/01-multiple-sessions.md) — Creare due conversazioni, elencare le Session e passare da una all’altra mantenendo i rispettivi contesti.
2. [Comandi](issues/02-commands.md) — Montare /help, /clear e /resume e aggiungere un comando personalizzato.
3. [Selezione](issues/03-selection.md) — Presentare le Session disponibili e riprendere quella scelta dall’utente.
4. [Interruzione](issues/04-response-stop.md) — Fermare una risposta in streaming e conservare il messaggio parziale.
5. [Processors](issues/05-message-processors.md) — Espandere un riferimento, per esempio @file, prima dell’invio all’Agent e mostrarlo in forma compatta nella conversazione.
6. [Input history](issues/06-input-history.md) — Richiamare ciò che l’utente aveva scritto, inclusi i comandi, e recuperare la bozza corrente.
7. [Preferenze](issues/07-preferences.md) — Ricordare una scelta dell’utente tra esecuzioni, per esempio il modello da usare.

## Modalità di lavoro

L’elenco è approvato. Il primo esempio è stato riscritto dall’utente ed è il
riferimento per lo stile: flusso concreto, execTurn(), showMessages(), stampa
leggibile e streaming. L’utente ha poi autorizzato gli altri sei esempi con
«prosegui con gli altri esempi secondo questo stile».

## Avanzamento

Tutti e sette gli esempi sono implementati in examples/bin/. I nuovi esempi
riutilizzano le funzioni di presentazione del primo tramite examples/src/functions.php;
il primo esempio dell’utente non è stato modificato.

- 01 — Più Session: 01-multiple-sessions.php.
- 02 — Comandi: 02-commands.php.
- 03 — Selezione: 03-selection.php, scelta reale del numero da terminale.
- 04 — Interruzione: 04-interruption.php, stop dopo il primo chunk e nuovo turno.
- 05 — Processors: 05-processors.php, riferimento a fixtures/trip.txt e proiezione display.
- 06 — Input history: 06-input-history.php, richiamo di input e ripristino bozza.
- 07 — Preferenze: 07-preferences.php, scelta lingua persistita tra processi.

Istruzioni di esecuzione in examples/README.md. Comandi, Selezione, Interruzione
e Processors sono stati eseguiti con OpenAI reale. Input history e Preferenze
sono stati verificati senza introdurre chiamate al modello non necessarie.
