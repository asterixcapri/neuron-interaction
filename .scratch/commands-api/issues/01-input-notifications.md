# 01: Inviare messaggi e ricevere notifiche attraverso Conversation

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction

**What to build:** Un terminale sottopone messaggi o Command a submitInput e riceve gli eventi nativi dell’Agent oppure Notification, senza adapter o dispatch nel client.

**Blocked by:** None (can start immediately)

Status: ready-for-agent

- [x] Gli input stringa, UserMessage e CommandInput seguono i rispettivi percorsi; slash in un UserMessage esplicito non attiva un Command.
- [x] Un Command run(): void usa il context concreto e notify con livello Info predefinito, Warning o Error; le notifiche mantengono l’ordine.
- [x] Il context fornisce gli accessi allo stato necessari al contratto; ConfigurationStore omesso crea un default in memoria isolato per Conversation, mentre uno store fornito viene riusato.
- [x] Il registro variadico conserva elenco e lookup e rifiuta nomi invalidi o duplicati; nessun Command viene montato automaticamente.
- [x] Un Command sconosciuto produce una Notification Error; le richieste già registrate da un Command che lancia vengono eseguite prima della propagazione della stessa eccezione.
- [x] I test pubblici preservano preparazione, cloning, allegati, identità/chiavi degli eventi nativi, AgentState e mancata esecuzione di stream non consumati.
- [x] Un esempio minimale e la documentazione del comportamento rendono verificabile l’ingresso unico senza introdurre nuovi adapter.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `988fc72` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
