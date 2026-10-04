# Command e input gestiti da Conversation

Status: ready-for-agent
Data: 2026-10-03
Repository coinvolti: neuron-interaction, neuron-tui, solaro

## Problem Statement

Oggi la Host Application deve implementare un adapter per usare i Command,
coordinare dispatch e completamento, presentare le Selection e avviare i prompt
prodotti dai Command. Queste responsabilità vengono replicate nei diversi client.
Un terminale semplice deve conoscere troppi dettagli della libreria; la TUI
concentra gestione e presentazione nell’adapter; un backend deve ricostruire un
proprio percorso equivalente.

Vogliamo usare neuron-interaction come estensione del modello di streaming di
neuron-ai: un solo ingresso per messaggi e Command e un solo stream da consumare.
La Host Application deve decidere la presentazione, l’ammissione delle operazioni
e la propria uscita, senza implementare il motore dei Command.

## Solution

Conversation offre submitInput come unico ingresso pubblico. Riconosce gli input,
esegue i Command attraverso un CommandContext concreto ed esegue le loro richieste
nell’ordine di registrazione. Il risultato osservabile è uno stream di eventi
nativi neuron-ai e di cinque tipi aggiuntivi: Notification, SelectionRequest,
ExitRequest, SessionChanged e AgentChanged.

I Command restano semplici metodi run che restituiscono void, senza yield o
adapter implementati dall’host. Possono cambiare lo stato, registrare notifiche,
richiedere scelte o uscita e registrare UserMessage con promptAgent. Conversation
esegue i prompt senza politiche aggiuntive di retry, arresto o ripresa.

Le SelectionRequest sono richieste di presentazione, non uno stato pendente della
Conversation. L’host associa la scelta al Command indicato nell’evento e rimanda
CommandInput con identificatore e valore. Lo stesso meccanismo funziona nella TUI
e tra richieste HTTP separate. Annullare un picker non invia input alla libreria.

## User Stories

1. As a library consumer, I want one submission method for messages and Commands, so that I do not maintain separate execution paths.
2. As a terminal application developer, I want to pass a typed line to Conversation, so that parsing and dispatch belong to the library.
3. As a TUI developer, I want to consume native Agent events and interaction events in one stream, so that I can present both with the same consumption loop.
4. As a Command author, I want run to return void, so that I do not need to implement a generator or client-specific result.
5. As a Command author, I want a concrete CommandContext supplied by the library, so that no Host Application must implement an adapter for my Command.
6. As a Command author, I want access to the current Agent and Session, so that I can implement state-changing operations.
7. As a Command author, I want access to SessionStore, so that I can list, create, retrieve and delete owned Sessions.
8. As a Command author, I want access to ConfigurationStore, so that I can read and save preferences.
9. As a library consumer, I want a configuration store available by default, so that a simple application does not need persistence setup.
10. As a Host Application developer, I want to supply a persistent configuration store, so that preferences survive executions and HTTP requests.
11. As a Command author, I want one notify method with a severity level, so that informational, warning and error messages use the same operation.
12. As a Host Application developer, I want Notification to carry text and severity, so that I choose how feedback appears.
13. As a Command author, I want to request labeled choices, so that each client can present an appropriate selection interface.
14. As a TUI user, I want to choose an option in the picker, so that the corresponding Command receives its opaque value.
15. As a TUI user, I want Escape to close the picker without invoking a Command, so that cancelling has no additional execution effects.
16. As a web application developer, I want command and value to identify a selection response, so that successive HTTP requests need no shared PHP object.
17. As a Command author, I want to interpret and validate my own arguments, so that the library does not restrict values to the displayed options.
18. As a Command author, I want to request another selection in a later invocation, so that I can implement multi-step interactions without an internal input loop.
19. As a minimal terminal user, I want to see the Command syntax next to each option, so that I can submit the choice without hidden pending state.
20. As a Command author, I want to request application exit, so that a terminal host can leave its interface.
21. As a web application developer, I want to ignore ExitRequest, so that a terminal-oriented request does not terminate my Conversation or process.
22. As a skill Command author, I want to expand a skill and register a UserMessage, so that Conversation executes the skill instructions through its normal message processing.
23. As a Command author, I want to register multiple prompts, so that I can define a sequence without an artificial single-prompt limit.
24. As a Command author, I want requests executed in registration order, so that a notification before or after a prompt appears in that position.
25. As a Host Application developer, I want native Agent events forwarded unchanged, so that existing text, tool, reasoning and adapter rendering remains usable.
26. As a Host Application developer, I want execution exceptions to reach my catch, so that Command and Agent failures share one error-handling mechanism.
27. As a Command author, I want state changes and registered requests preserved after a failure, so that the library does not impose rollback or cancellation policies.
28. As a terminal user, I want an unknown Command reported as a Notification, so that an ordinary input mistake is visible without an execution exception.
29. As a Host Application developer, I want to decide Command admission, so that authorization and concurrency follow my application's rules.
30. As a TUI user, I want the existing busy-time availability preserved by the TUI, so that help and exit remain available without enabling all state-changing Commands.
31. As a Host Application developer, I want events when the selected Agent or Session changes, so that I can update indicators and displayed History.
32. As a Solaro user, I want model selection and skill invocation preserved, so that the new interface does not remove useful application behavior.
33. As a Solaro user, I want skill-name precedence resolved explicitly, so that stricter Command registration preserves existing invocation choices.
34. As a maintainer, I want examples showing one capability at a time, so that the new contract is understandable without adapter or scheduling boilerplate.
35. As a maintainer, I want behavior tested through public submission and host integrations, so that refactoring private request storage does not invalidate the tests.

## Implementation Decisions

### Conversation e input

- Conversation resta il modulo di interazione con un Agent nella Session
  selezionata. submitInput sostituisce submitMessage come ingresso pubblico unico.
- submitInput accetta string, UserMessage o CommandInput e restituisce un Generator
  di oggetti. Una stringa viene interpretata come Command se inizia con la sintassi
  prevista per un identificatore slash; altrimenti diventa un UserMessage.
- UserMessage esplicito non viene interpretato come Command, anche quando il testo
  inizia con slash. Conserva allegati, metadati e il percorso dei processor.
- CommandInput contiene identifier e value; il valore predefinito è la stringa
  vuota. parse distingue una linea Command da un normale messaggio e mantiene
  lo slash nell’identificatore. Il valore conserva il testo degli argomenti dopo
  il separatore, senza interpretare il contenuto o i valori delle opzioni.
- Una linea vuota o composta soltanto da spazi non avvia l’Agent. Un UserMessage
  esplicito vuoto continua a essere soggetto alla validazione esistente.
- Agent e SessionStore restano dipendenze della Conversation. Sono conservate le
  opzioni per Session iniziale, StopSignal e UserMessageProcessors. Si aggiungono
  Commands, ConfigurationStore e una closure di ammissione dei Command.
- Commands omesso corrisponde a un registro vuoto, senza montaggio automatico
  dei built-in. ConfigurationStore omesso viene creato con un nuovo storage in
  memoria e identità locale, separato per istanza. La TUI passa alla Conversation
  lo store che già possiede, configurato dall’app o creato dal suo default.
- La preparazione dei messaggi conserva cloning, allegati, metadati e validazione
  esistenti. Ogni UserMessage passa per i processor una sola volta, anche quando
  nasce da promptAgent. I messaggi umani sono preparati al momento della submission;
  i prompt dei Command quando la richiesta registrata viene raggiunta.
- La submission restituisce uno stream senza avviare l’Agent. Dispatch dei Command
  ed esecuzione delle richieste avvengono consumando lo stream. Gli stream nativi
  conservano gli oggetti, le loro chiavi, la loro sequenza e il valore AgentState.
- Il Generator restituisce l’ultimo AgentState ottenuto da un messaggio ordinario
  o da un prompt del Command; restituisce null se nessun messaggio viene eseguito.
  Non introduce un nuovo risultato pubblico di completamento dei Command.

### Command e CommandContext

- CommandInterface mantiene name e describe e cambia run affinché riceva un
  CommandContext e il valore stringa. run restituisce void; niente yield nei Command.
- CommandContext è una classe concreta final fornita dalla libreria. Ogni
  esecuzione riceve un context con una raccolta nuova di richieste.
- Il context espone agent, useAgent, session, useSession, sessionStore,
  configurationStore e commands. commands restituisce una lista di CommandInterface
  per consultazione, senza un registro con operazioni di dispatch.
- Il context non espone Conversation, submitInput, il motore, un registro
  eseguibile o hook del dispatcher. L’accesso all’Agent reale non deve essere usato
  per aggirare Conversation chiamando direttamente stream: il percorso previsto
  per richiedere una risposta è promptAgent.
- Le operazioni di stato sono immediate e mantengono binding e verifica di
  ownership esistenti. La sostituzione esplicita della Session o dell’Agent rende
  disponibile l’evento corrispondente nella sequenza degli effetti.
- notify riceve testo e NotificationLevel, con Info come default. I livelli
  disponibili sono Info, Warning ed Error. Non sono presenti warn ed error separati.
- requestSelection riceve SelectionRequest; requestExit registra ExitRequest;
  promptAgent riceve UserMessage. Non è presente un metodo generico request.
- Le richieste vengono registrate durante run ed elaborate dopo l’invocazione
  nell’ordine originale. notify, promptAgent, notify produce una notifica, lo
  stream della risposta e poi la seconda notifica. Non si riordina per categoria.
- CommandResult può restare una struttura interna per richieste e fallimento;
  non impone al consumer di eseguire prompt o riprendere il dispatch. La forma
  privata della raccolta non è parte del contratto da testare.

### Eventi e richieste

- I cinque tipi aggiuntivi sono Notification, SelectionRequest, ExitRequest,
  SessionChanged e AgentChanged. Nessun CommandFinished o evento di fallimento.
- Notification contiene testo e NotificationLevel. È feedback destinato alla
  persona, non un messaggio da inviare all’Agent.
- SelectionRequest contiene command, prompt, options e description facoltativa.
  Ogni SelectionOption contiene value, label e description facoltativa. Le opzioni
  sono una lista ordinata non vuota e conservano i valori opachi.
- SelectionRequest non contiene callback, valore selezionato o continuazione.
  Conversation la emette senza conservarla come richiesta pendente.
- La Host Application presenta le opzioni. Quando l’utente sceglie, sottopone
  CommandInput con il Command della richiesta e il valore scelto. Il Command
  interpreta il valore anche se non compare tra le opzioni; niente choose o
  validazione implicita della Selection nel percorso di submission.
- Un valore slash in CommandInput resta un valore: una risposta a model che
  contiene help non esegue HelpCommand. Una nuova stringa slash sottoposta come
  input ordinario viene invece interpretata come nuovo Command.
- Nella TUI l’annullamento chiude il picker senza inviare input. Non sono previsti
  pendingSelection o cancelSelection sulla Conversation. Nel terminale minimale
  si possono mostrare identificatore e valore come sintassi da digitare; un picker
  costruisce direttamente il CommandInput.
- Via HTTP la prima richiesta riceve la SelectionRequest e termina. Il browser
  conserva i dati per presentare la scelta e invia command e value in una nuova
  richiesta. Il backend ripristina Session e configurazione e usa CommandInput;
  non serve persistere l’istanza di Conversation o la Selection nel server.
- Più SelectionRequest o altre richieste non vengono vietate o annullate dalla
  libreria: vengono emesse in ordine. La Host Application decide la presentazione
  e il Command è responsabile di produrre un flusso sensato.
- ExitRequest è una richiesta alla Host Application. Non termina Conversation,
  non interrompe l’Agent, non annulla richieste e non chiude il processo.
  Terminale e TUI possono usarla per uscire; il web può ignorarla. Nessun ended.
- SessionChanged contiene la Session selezionata; AgentChanged contiene l’Agent
  selezionato. Servono a sincronizzare la presentazione dell’host. Una modifica
  diretta all’Agent che deve essere segnalata usa anche useAgent esplicito.
- promptAgent non produce un evento dedicato al prompt. Conversation lo esegue
  e inoltra gli eventi neuron-ai; niente UserMessageSubmitted o CommandPrompt
  in questa fase. Il prompt non deve essere mostrato come testo scritto dalla
  persona per il solo fatto di essere stato generato dal Command.

### Esecuzione, errori e admission

- Più prompt sono ammessi e vengono eseguiti sequenzialmente nella posizione
  delle rispettive richieste. Non ci sono politiche aggiuntive di retry, ripresa
  o arresto in base a response stop o approval. Un ritorno normale permette di
  passare alla richiesta successiva; un’eccezione interrompe naturalmente il ciclo.
- Se run lancia, gli effetti di stato restano applicati e le richieste già
  registrate vengono eseguite. Dopo queste richieste viene propagato il Throwable
  originale; nessuna Notification di fallimento automatica o rollback.
- Se una richiesta fallisce durante l’esecuzione, l’eccezione propaga e interrompe
  il percorso corrente. Non si aggiungono recuperi per eseguire richieste residue,
  aggregazione di eccezioni o mascheramento dell’errore nativo.
- Un Command sconosciuto produce una Notification di livello Error e non viene
  eseguito. Il rifiuto di admission produce una Notification di livello Warning
  che spiega il rifiuto, senza eseguire il Command o applicarne effetti. I testi esatti non
  diventano un protocollo da cui l’host deve dedurre gli esiti.
- La closure di admission riceve CommandInterface e restituisce bool; è
  facoltativa e senza closure i Command registrati sono ammessi. Si valuta
  prima di ogni invocazione, anche quando è una risposta a una SelectionRequest.
  Un’eccezione della closure propaga senza eseguire il Command.
- ConcurrentCommandInterface viene rimosso. L’host può usare scheduler,
  autorizzazione, classi o un proprio marker; Conversation non classifica
  automaticamente i Command per concorrenza.
- La TUI conserva la propria politica: mentre lavora rifiuta i Command ordinari
  e mantiene help e uscita disponibili, identificando i Command secondo la
  propria integrazione. Coda umana, task, approvazioni, title scheduling e
  interruzione restano responsabilità dell’host.
- Una risposta già avviata conserva Agent e Session catturati. I cambi ammessi
  influenzano le esecuzioni successive. Non si introduce un divieto generale di
  useSession durante uno stream o un lock distribuito sulle Session.

### Registro e migrazione coordinata

- Commands viene costruito con CommandInterface variadici e conserva all e named.
  Rifiuta identificatori invalidi e duplicati prima dell’esecuzione. Non introduce
  alias o mount. Il consumer non deve invocare direttamente il registro per usare
  Conversation.
- La migrazione rimuove CommandAdapterInterface, AbstractCommandAdapter,
  afterExecution, generics di output e il vecchio protocollo CommandExecution.
- HelpCommand, ExitCommand, ClearCommand e ResumeCommand passano al context.
  Help usa la lista consultabile, Leave richiede uscita, Clear e Resume cambiano
  Session; Resume può chiedere una SelectionRequest prima della scelta.
- neuron-tui continua a costruire la propria Conversation internamente. Passa
  Commands e ConfigurationStore e consuma i nuovi eventi. Il picker sottopone
  CommandInput, senza dispatch o nuovo adapter. Nessuna coda aggiuntiva dei prompt
  dei Command: Conversation li esegue nello stream corrente.
- solaro migra ModelCommand, ReviewCommand e SkillCommand al context e mantiene
  modello persistente, catalogo, refresh ed espansione delle skill. SkillCommand
  usa promptAgent per il messaggio espanso. Review è un esempio e non impone
  ulteriori capacità di gestione.
- Gli esempi mantengono un vantaggio ciascuno: Command e prompt nel percorso
  dedicato ai comandi, Selection reale nel percorso dedicato alla scelta.
  Tutti gli usi di submitMessage vengono migrati e l’adapter dimostrativo eliminato.
- Il lavoro coinvolge tre repository e richiede branch e PR coordinati per
  repository, non una singola PR capace di includere tutti i progetti. Le
  dipendenze locali devono risolvere revisioni compatibili e la validazione
  congiunta precede una futura pubblicazione.
- La priorità attuale delle skill di solaro sui built-in viene risolta nell’host
  prima di costruire Commands: non si delega più alla presenza di duplicati.
  Questo aggiorna il meccanismo registrato in ADR-0002 di solaro preservandone
  l’intento di invocazione deterministica.
- La migrazione aggiorna le decisioni TUI degli ADR-0003, ADR-0006 e ADR-0007
  limitatamente a ingresso, adapter e completamento. Mantiene composizione TUI,
  ownership, scheduling dell’host e Selection senza stato nel core. Non modifica
  lo schema persistito delle Session o le decisioni sul loro storage.

## Testing Decisions

- La seam principale è l’interfaccia pubblica della Conversation, soprattutto
  submitInput. I test consumano lo stream e verificano eventi, ordine, eccezioni,
  AgentState e stato osservabile di Agent, Session e store. Non leggono raccolte
  private di richieste o flag dell’implementazione.
- Le seams esistenti della TUI e dei Command applicativi coprono presentazione,
  picker, ammissione, modello e skill. Non si crea un nuovo adapter per i test.
  L’utente ha confermato questa impostazione durante la sintesi.
- Come prior art si usano i test attuali di Conversation per identità degli eventi,
  preparazione eager, stream non consumati, ownership, contesto catturato,
  response stop e rilascio delle risorse. I test della Selection sono il riferimento
  per due invocazioni separate e conservazione dei valori opachi.
- Notification viene verificata per testo e livello e per default Info; notify è
  l’unico metodo. Sono coperti Command sconosciuto e admission rifiutata senza
  contatto con l’Agent o effetti del Command.
- Si verificano ordine tra notifica, prompt e seconda notifica; più prompt;
  nessuna politica aggiuntiva su return normale, response stop o approval;
  propagazione di errori e assenza di retry.
- Un Command che registra richieste e poi lancia deve emetterne gli effetti prima
  di propagare la stessa eccezione. Una richiesta che fallisce interrompe invece
  naturalmente la sequenza. Una nuova invocazione non deve ereditare richieste.
- SelectionRequest viene testata senza stato pendente: risposta CommandInput,
  valore slash passato al Command, valori non limitati alle opzioni, scelta a
  più passi e annullamento del picker senza chiamate alla Conversation.
- Un test simula due richieste HTTP creando due Conversation con gli store e la
  Session corretti: la seconda riceve command e value, senza ripristinare la
  Selection. Non serve costruire un server HTTP per verificare questo contratto.
- I test esistenti della TUI per picker, suggerimenti, draft, InputHistory,
  proiezione delle etichette, allegati, admission, coda e interruzione vengono
  adattati senza perdere copertura. Il preview dei prompt generati non deve essere
  simulato con un evento non previsto: History persistita e visualizzazione dei
  messaggi umani restano verificabili tramite i percorsi esistenti.
- I test applicativi di solaro verificano espansione delle skill, metadati,
  argomenti, errore di lettura, modello/refresh e risoluzione delle collisioni
  prima del registro. I vecchi mock degli adapter sono sostituiti da verifiche
  degli effetti osservabili attraverso Conversation dove appropriato.
- Test dei nomi e parsing possono esercitare direttamente il contratto di
  CommandInput e Commands; non sostituiscono i test di comportamento integrato.
- In ogni repository con modifiche PHP si eseguono formatter, PHPStan e test
  PHPUnit rilevanti, rispettando configurazioni e standard del progetto. Prima
  di un eventuale push o rilascio si eseguono anche suite completa e check di stile.
- Gli esempi che interrogano l’Agent mostrano lo streaming reale; il comportamento
  dei contratti viene verificato senza dipendere da chiamate remote al provider.

## Out of Scope

- Adapter implementati dall’host, hook di completamento e risultati generici.
- yield nei Command o accesso diretto del context al motore e al dispatcher.
- CommandFinished, eventi pubblici di fallimento, UserMessageSubmitted,
  CommandPrompt e richieste generiche specifiche di client.
- Stato Selection pendente nella Conversation, cancelSelection, ended o fine
  irreversibile della Conversation per una richiesta di uscita.
- Validazione automatica delle risposte rispetto alle opzioni di una Selection.
- Limiti artificiali a un solo prompt o divieti di combinare richieste, e politiche
  aggiuntive di retry, rollback, resume o arresto dei flussi dei Command.
- Coda persistente, scheduling dell’host nel core, lock distribuiti, trasporto
  HTTP/SSE, replay o persistenza delle richieste di Selection nel server.
- Alias, mount o classificazione condivisa dei Command concorrenti.
- Migrazioni del formato storage e della History, nuove funzionalità di review,
  rilasci, push e pubblicazione remota.

## Further Notes

Questo documento consolida il confronto e sostituisce le proposte precedenti
su adapter, Selection pendente, prompt rinviati ed eventi aggiuntivi dei prompt.
Il vecchio handoff è storico e non definisce un contratto alternativo.

Nota storica della fase di sintesi: le otto issue allora presenti erano bozze
antecedenti alla chiusura del confronto. Questa fase chiedeva soltanto la
riscrittura dei ticket, senza modifiche PHP o commit. Il task graph definitivo è
stato successivamente approvato e registrato nell'indice README di questa cartella;
il workflow implement-spec ne ha autorizzato implementazione, commit e PR.

Le modifiche precedenti dell'utente a glossario, esempi e ADR vanno preservate.
L'autorizzazione successiva «i push li puoi fare» consente push e PR remote
coordinate dopo la validazione, superando l'esclusione storica di push e
pubblicazione remota. Non autorizza un rilascio dei pacchetti.
