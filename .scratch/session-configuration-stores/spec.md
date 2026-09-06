# SessionStore e ConfigurationStore per utente

Status: completed

## Problem Statement

Le Host Application che usano neuron-interaction hanno bisogno di conservare
conversazioni e configurazioni dell'interazione con un modello comune, senza
duplicare la persistenza. Attualmente Sessions gestisce conversazioni senza un
proprietario esplicito; non esiste un modulo dedicato alle configurazioni.

La demo neuron-tui conserva l'Agent corrente in memoria e il suo cambio modello
non è una configurazione persistita. AgentDeck ricostruisce invece il contesto
dalle richieste HTTP. Le due applicazioni devono poter usare la stessa libreria
con regole applicative indipendenti: non devono necessariamente condividere
dati, esecuzione o selezioni attive.

Serve distinguere una Session, che è anche la History usata da Neuron, da una
Configuration con valori definiti dall'applicazione. Serve inoltre filtrare le
Session per metadata, sempre nell'ambito di un utente.

## Solution

Introdurre SessionStore e ConfigurationStore sopra StorageInterface. Entrambi
ricevono Storage e userId alla costruzione: il chiamante non ripete l'identità
nelle singole operazioni e i Command ricevono Store già associati all'utente.

Session conserva proprietario, messaggi e metadata e continua a persistere
automaticamente gli aggiornamenti della History. Configuration conserva una
chiave scelta dall'applicazione, un proprietario e valori JSON liberi. Le sue
modifiche restano in memoria fino a ConfigurationStore.save.

L'applicazione decide quale Configuration caricare: una configurazione può
essere trasversale alle Session nella TUI oppure associata alla singola Session
in AgentDeck. La libreria non impone quella relazione.

Il completamento comprende anche l'adeguamento di neuron-tui alle nuove
interfacce: dipendenze, composizione della TUI, Command Adapter, demo e test.
Non basta completare neuron-interaction lasciando il suo client TUI non
funzionante. AgentDeck rimane un'integrazione successiva.

## User Stories

1. As a Host Application developer, I want to construct each Store with an explicit userId, so that subsequent operations use one consistent owner.
2. As a Host Application developer, I want both Stores to use StorageInterface, so that file and in-memory persistence remain interchangeable.
3. As a TUI application developer, I want to provide a stable local user identity, so that a personal application does not require authentication infrastructure.
4. As a web application developer, I want to supply the authenticated user's identity, so that conversation access is scoped to that user.
5. As a user, I want to create a Session with an automatically generated key, so that each conversation is distinct.
6. As a user, I want to reopen my Session by key, so that I can continue its persisted History.
7. As a user, I want Session searches to return only my conversations, so that another user's conversations do not appear in my results.
8. As a Host Application developer, I want generic Session metadata, so that I can organize conversations by application-specific attributes.
9. As a user, I want to filter Sessions by several metadata values together, so that I can find conversations relevant to my current context.
10. As a Host Application developer, I want metadata to survive History updates, so that answering a message does not erase application information.
11. As a Neuron integration developer, I want Session to remain usable as Chat History, so that message persistence does not require a separate save after every response.
12. As a user, I want text, reasoning, tool messages and supported content to survive reopening, so that the stored conversation remains faithful to the interaction.
13. As a user, I want Session summaries to retain their current title and ordering behavior, so that conversation selection remains useful.
14. As a Host Application developer, I want to choose a Configuration key, so that I can reopen preferences without storing a generated identifier elsewhere.
15. As a user, I want Configuration keys scoped to my identity, so that different users can each have a configuration named default.
16. As a Host Application developer, I want Configuration to accept arbitrary JSON values, so that the library does not prescribe model, provider, tools or other application options.
17. As a user, I want to update an existing configuration value, so that changing model preserves unrelated options.
18. As a Host Application developer, I want to add and remove configuration values, so that application preferences can evolve.
19. As a Host Application developer, I want to modify related values before saving, so that a provider and model change can be persisted together.
20. As a user, I want a saved Configuration to survive a fresh Store instance, so that restarting the application retains my choices.
21. As a Host Application developer, I want absent reads to return null, so that I can decide how to handle missing configurations and conversations.
22. As a Host Application developer, I want explicit deletion operations, so that my application can manage the lifecycle of persisted data.
23. As a Command author, I want to use a SessionStore already scoped to the current user, so that Commands do not choose or authenticate an identity.
24. As a library maintainer, I want to replace the old interfaces directly, so that supporting this design does not require compatibility wrappers or data migration.
25. As a neuron-tui user, I want the application and demo to run with the updated neuron-interaction dependency, so that the persistence redesign does not leave my terminal client broken.
26. As a TUI application developer, I want to supply a user-scoped SessionStore at composition time, so that built-in Commands operate on the intended user's conversations without handling identity themselves.
27. As a TUI user, I want Clear and Resume to preserve their current interaction flow and persist my History, so that changing the Store interface does not change how I manage conversations.
28. As a TUI application developer, I want an explicitly supplied Agent History to remain installed at startup, so that adopting SessionStore does not discard or replace my application's conversation.
29. As a maintainer, I want automated checks in both repositories against the same new library contract, so that passing tests against an older installed dependency cannot conceal integration failures.

## Implementation Decisions

- Scope includes neuron-interaction and the dependent neuron-tui integration. Both repositories' affected examples and tests must use the new contract. AgentDeck integration remains separate follow-up work.
- Replace Sessions with SessionStore. Introduce ConfigurationStore and Configuration. Do not introduce a public generic persistence mechanism parameterized by arbitrary PHP classes.
- Both Stores receive StorageInterface and a required userId in their constructors. Neither Store chooses a default identity. No userId method is needed on CommandAdapterInterface for this design.
- Session and Configuration expose their logical key and owner. The owner is separate from application metadata or configuration values and is not changed through those generic setters.
- SessionStore.create accepts optional metadata, generates a Session key and immediately persists the new empty Session for the Store's user.
- SessionStore.read accepts a key and returns a Session or null. Normal keyed reads and deletion remain scoped to the Store's user; another user's document is not returned by read.
- SessionStore.summaries accepts an optional metadata filter and returns SessionSummary objects for the Store's user. Retain the existing exclusion of empty conversations, title derivation from user-authored content and most-recent-use ordering.
- SessionStore.delete accepts a key. Deleting an absent Session is a no-op. Exposing deletion does not automatically add a destructive Command to any application.
- Session retains its Neuron Chat History contract. Adding, clearing or trimming messages continues to persist the resulting History automatically, without requiring an application-level SessionStore.save call.
- Session exposes getMetadata, setMetadata and removeMetadata. Metadata updates persist automatically and preserve the History. History updates preserve application metadata and ownership.
- Application metadata remains a map of camelCase string keys to string values, consistent with current Storage. System-managed ownership and last-used information must not be overwritable through application metadata; the concrete storage layout is an implementation detail.
- Metadata filters use exact string equality with all conditions combined in AND. A missing metadata key fails that condition. Other metadata do not affect matching. No nested JSON query language is introduced.
- ConfigurationStore.create accepts a caller-chosen key and optional values and immediately persists the new Configuration. Creation of an existing key for the same user fails without overwriting it.
- Configuration keys are unique within an owner. Different users may use the same logical key without reading, overwriting or deleting each other's Configuration. Physical key encoding is owned by the persistence implementation, not callers.
- ConfigurationStore.read accepts a key and returns Configuration or null. ConfigurationStore.delete accepts a key and is a no-op if the Configuration is absent.
- Configuration exposes getKey, getUserId, has, get with an optional default, set, remove and all. A value may be a JSON scalar, null, list or nested object represented as JSON-compatible PHP data. No automatic persistence of arbitrary PHP objects or class hydration is introduced.
- Configuration.set adds or replaces one value in memory. Configuration.remove removes a value in memory. Neither operation writes to Storage.
- ConfigurationStore.save accepts the modified Configuration and explicitly persists its values. Updating model preserves unrelated values. Multiple in-memory changes are persisted as one complete configuration document, rather than one write per setter.
- The supported save flow is to create or read a Configuration through a Store, modify it, and save through that Store. The disputed cross-Store and deletion-between-read-and-save cases are recorded in Further Notes rather than assigned unapproved behavior.
- Configuration does not require agentId, model, provider, tools or any other application field. The Host Application validates their meanings and constructs the Agent. Persisting Configuration does not automatically replace an Agent already in memory.
- There is no mandatory Session-to-Configuration reference. A Host Application may choose related keys or an explicit association. Ownership alone does not select an active Configuration or Session.
- StorageInterface remains a generic document contract with namespaces, keys, data and metadata. Support caller-chosen keys on create alongside generated keys, and metadata filtering on entries. Preserve existing read-null, write-create-or-replace and delete-if-present semantics at the Storage level.
- Storage creation with a supplied existing key must not overwrite its document, including competing creation attempts. Broader update conflict detection is not added by this specification.
- Adapt the library's Command adapter contract and built-in Session Commands to use SessionStore. Resume selection still invokes the Command again with the chosen key; missing reads are handled as a Command failure or warning, not as an implicit Session creation. Clear continues to create a new Session while preserving the previous one.
- No compatibility aliases or migration of ownerless data are required. Update library examples and tests directly to the new interfaces. Do not silently assign old data to an arbitrary user.
- Update neuron-tui's dependency resolution to the neuron-interaction revision implementing this specification, including the demo's separate Composer dependency graph and applicable lockfiles. Validate against the new library, not the previously installed version. Local development may use a reproducible local dependency override; do not commit machine-specific absolute paths or treat release publication as required authorization.
- Replace the old Sessions type throughout neuron-tui's composition, input dispatch, TuiAdapter, fixtures and examples with SessionStore. Adapt construction, create/read operations and missing-result handling; this is an integration change rather than a compatibility wrapper.
- Resolve the TUI's local identity at composition time: explicitly configured identity first, operating-system user when available, then a stable local fallback. A caller-supplied SessionStore already determines its user and must not be replaced or rebound by the TUI. Keep identity resolution outside neuron-interaction's Stores and outside individual Commands.
- Preserve startup with a Host Application-supplied History, including non-Session History. Preserve existing behavior for default versus explicitly supplied persistence, without silently registering external History as a new Session.
- Preserve the TUI's selection continuation, cancellation, busy-Command policy, automatic History persistence, and useAgent behavior that transfers the current History. Clear creates a new Session for the scoped user and leaves the previous one stored; Resume lists and opens only that user's Sessions.
- The demo must start successfully with the new Store and identity wiring. Its existing model-switch Command must continue to work and preserve History. Adding persisted model preferences to that Command is a separate feature; ConfigurationStore does not require every application to adopt a new model policy in this integration.
- Update affected neuron-tui usage documentation and examples to describe the new composition. Input History remains independent from Session History; changing its ownership model is not part of this work.

## Testing Decisions

- Test through the public SessionStore, ConfigurationStore and returned object interfaces. Assert observable persistence and returned values, not private helper calls, file layout or serialization implementation details.
- Exercise Store behavior with both InMemoryStorage and FileStorage, following the existing Sessions tests that reopen conversations using fresh instances. Reopen file-backed objects through a fresh adapter to establish durable persistence.
- Verify user scoping for Session creation, keyed read, summaries and deletion. Verify two users can use the same Configuration key independently through normal Store operations.
- Verify exact AND metadata filtering, absent filter keys, preservation of metadata across History changes, and preservation of messages across metadata updates.
- Follow existing Session tests for rich message round trips, reasoning, tools, content blocks, History clearing and trimming. Exercise automatic persistence through Neuron's public History operations without requiring a live LLM.
- Preserve existing SessionSummary title, empty-session and ordering coverage through the renamed Store.
- Verify Configuration create and reopen, null reads, duplicate create failure, nested JSON values, null versus absent values, default reads, and rejection of values outside the JSON contract.
- Verify Configuration setters do not persist: a fresh read sees the old values before save and the complete new values after save. Verify adding, replacing and removing values preserves unrelated values.
- Verify deletion of absent and existing documents. Do not assert the two disputed save edge-case rules described in Further Notes.
- Extend existing Storage adapter contract tests for supplied creation keys, no-overwrite creation and filtered entries. Keep lower-level tests limited to those adapter guarantees rather than duplicating all Store scenarios.
- Adapt existing Command and backend example tests to user-scoped SessionStore, including empty selection, selection response, missing Session and Clear preserving the previous conversation.
- Run PHPUnit and PHPStan in both neuron-interaction and neuron-tui against the updated dependency. Verify the demo resolves the new dependency as well; a passing library suite alone does not complete this specification.
- Extend neuron-tui's existing public Tui interaction tests and Session composition tests rather than introducing a second test abstraction. Cover caller-supplied and default composition, explicit local identity and fallback, preservation of external History, scoped Session selection, missing reads, cancellation, Clear and automatic persisted History reopening.
- Exercise a TUI flow that starts a Session, receives messages, clears, and resumes the earlier conversation through SessionStore. Verify another user's Session is not offered. Use the existing fake terminal and Agent/provider fixtures; no live LLM or credentials are required for automated acceptance.
- Preserve tests for useAgent transferring History and model switching where covered. Verify a demo startup/exit smoke flow without making a paid provider request; document any interactive-only validation limitation instead of claiming an unperformed check.
- No network or remote-client integration tests are required. neuron-tui uses the shared library locally and remains a separate application from AgentDeck.

## Out of Scope

- Remote TUI clients, HttpStorage, HTTP endpoints, Symfony controllers and authentication infrastructure.
- Shared live state or synchronization between independent TUI and web applications.
- AgentDeck's AG-UI Command encoder refactor, frontend routing and localStorage preferences.
- New model selection, persisted model-preference or Agent construction policies in either Host Application; preserving existing neuron-tui behavior while adapting its dependencies is in scope.
- Generic object persistence, automatic PHP class serialization or a configurable ORM.
- Mandatory configuration fields or mandatory attachment of Configuration to Session.
- Compatibility with the previous Sessions interface and migration of existing documents.
- Revision-based update conflicts, distributed transactions, synchronization of running Agents and merging concurrent edits.
- Ticket creation and production implementation as part of publishing this specification.

## Further Notes

The agreed test surface is the public Stores with real memory/file adapters and
the Session's Neuron History contract. This was discussed before publication;
no new testing abstraction is needed.

Two suggested restrictions were explicitly rejected by the user: requiring a
Store to reject an object originating from a different user's Store, and
requiring save to fail when a Configuration was deleted after it was loaded.
Their replacement semantics were not settled. This specification does not
reinstate either restriction, infer a transfer-of-ownership operation, or replace
save with a key-and-values write operation. Resolve those edge cases before
advertising guarantees or adding tests for them; they do not alter the agreed
normal create/read/modify/save flow.

The existing Storage write contract does not prevent lost updates between
independent readers. Keeping that limitation is the proposed initial scope,
not a promise of concurrent editing support. Non-overwriting creation is a
separate requirement from update conflict handling.

The specific representation of ownership, logical keys and system metadata
inside generic Storage remains an implementation choice. Preserve the public
user-scoped behavior without requiring callers to encode identifiers into
storage-safe physical names.

Naming in this specification deliberately uses SessionStore in place of
Sessions. Existing domain descriptions and examples that name the old module
should be aligned as part of implementation. No glossary or ADR was created
as part of this specification.

The later scope decision explicitly includes neuron-tui adaptation and
verification. The feature is not complete until both repositories' checks pass
against the updated contract and the demo has been checked. It does not require
AgentDeck changes, release publication, a remote TUI, or shared application data.
