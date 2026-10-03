# 04: Cambiare Session e Agent con eventi osservabili

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction

**What to build:** Clear, Resume e un Command che cambia Agent aggiornano lo stato della Conversation e informano l’host, conservando ownership e contesto dei messaggi successivi.

**Blocked by:** 02

Status: ready-for-agent

- [x] useSession e useAgent applicano subito lo stato e rendono disponibili SessionChanged e AgentChanged nella sequenza degli effetti.
- [x] ClearCommand crea e seleziona una Session; ResumeCommand seleziona direttamente una Session o chiede una SelectionRequest e completa la scelta nella seconda invocazione.
- [x] Le Session straniere o mancanti restano soggette alla validazione di ownership; gli accessi a SessionStore e metadati restano utilizzabili dai Command.
- [x] I messaggi successivi usano lo stato selezionato; una risposta già avviata conserva Agent e Session catturati.
- [x] I cambi di stato già avvenuti restano applicati anche se il Command lancia; le notifiche di cambio registrate rimangono osservabili prima dell’eccezione.
- [x] I test pubblici e gli esempi coprono clear/resume, scelta in due passi e cambio Agent senza adapter o nuove restrizioni sui flussi.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `9fdfdec` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
