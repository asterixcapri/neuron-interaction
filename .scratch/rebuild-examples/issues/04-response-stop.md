# 04 — Interruzione

Status: needs-triage
Implementation: complete

## Obiettivo

Fermare una risposta in streaming e conservare il messaggio parziale.

## Vincoli

Seguire le [decisioni approvate](../spec.md) e lo stile del primo esempio
riscritto dall’utente. L’implementazione degli esempi restanti è stata autorizzata
con «prosegui con gli altri esempi secondo questo stile».

## Verifica

L’esempio deve rendere visibile il vantaggio descritto. Se interroga l’Agent,
usare OpenAI reale e mostrare lo streaming. Aggiornare le istruzioni di esecuzione
insieme all’esempio.

## Implementazione

Implementato in examples/bin/04-interruption.php. Istruzioni e risultati attesi in
examples/README.md. Le dipendenze appartengono al progetto Composer degli esempi.

## Verifica eseguita

PHPStan degli esempi e della libreria: nessun errore.
Test della libreria: 229 test passati.
