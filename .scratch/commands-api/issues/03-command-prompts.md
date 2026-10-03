# 03: Eseguire i prompt dei Command nello stesso stream

Spec: [Command e input gestiti da Conversation](../spec.md)

Repository: neuron-interaction

**What to build:** Un Command personalizzato registra UserMessage con promptAgent e il consumer riceve la risposta dell’Agent nello stesso stream, nella posizione delle richieste registrate.

**Blocked by:** 01

Status: ready-for-agent

- [x] promptAgent registra UserMessage senza yield nei Command o accesso diretto al motore; non produce un evento dedicato al prompt.
- [x] Notifica, prompt e seconda notifica producono notifica, eventi nativi della risposta e seconda notifica.
- [x] Sono ammessi più prompt eseguiti in ordine; un ritorno normale, anche con response stop o approval, non aggiunge una politica di arresto della sequenza.
- [x] Un errore durante l’esecuzione di una richiesta interrompe naturalmente la sequenza e propaga, senza retry o recupero delle richieste residue.
- [x] Un prompt registrato prima di un’eccezione del Command viene eseguito prima di propagare quell’eccezione, se le richieste terminano normalmente.
- [x] Ogni prompt conserva allegati/metadati e attraversa i processor una volta; lo stream restituisce l’ultimo AgentState o null se non esegue messaggi.
- [x] ExplainCommand e l’esempio dedicato ai Command dimostrano la capacità con streaming visibile; i test non dipendono da un provider remoto.

## Strategia di migrazione

Questo ticket appartiene alla migrazione incompatibile coordinata dei tre
repository. Lavora sul branch di integrazione del repository indicato, con
le revisioni compatibili dei suoi blocker. I test mirati rendono verificabile
la slice; l’intera suite dei tre progetti deve risultare verde al ticket 08.
Non pubblicare revisioni intermedie e non creare bridge pubblici fuori dallo spec.

## Comments

Implementato nel commit `abb81c4` sul branch coordinato `feat/commands-api`.
Validazione completa e revisione finale nel ticket 08.
