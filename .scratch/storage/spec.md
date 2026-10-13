# Ordered key-value Storage with one JSON file per entry

Status: ready-for-agent

## Problem Statement

Applications need one predictable way to persist Sessions, messages, preferences,
input history and stop signals. The current Storage mixes values with document
metadata and metadata queries. A Session document contains its entire message
history, so appending a message or changing Session metadata can rewrite a growing
conversation. Concurrent read-modify-write operations can overwrite each other.
Listing Sessions repeatedly reads their documents and deserializes messages.
Input history is a single growing value and is not isolated by user.

The redesign must provide a clean, coherent interface resembling an ordered table
of namespaced entries. Compatibility with the current interface and stored format
is not required. File persistence must remain available without a single global
data file, JSONL logs, compaction, or format rules tied to namespace names.

## Solution

Provide one StorageInterface for entries identified by namespace and key, ordered
by their first insertion. Each message and submitted input is its own entry.
Session information and user preferences are separate records.

FileStorage stores one complete JSON file per entry. A write changes only the
affected entry. InMemoryStorage implements the same observable contract.
create inserts without replacement, set inserts or replaces, and update atomically
changes or removes selected fields, including nested fields. Callers do not choose
a persistence format or manage locks.

A MessageStoreInterface adapter persists Neuron messages through this same Storage.
User-specific namespaces establish ownership. Session listings read small Session
records rather than message histories. Input history is isolated by user and has
no maximum entry count.

## User Stories

1. As a library consumer, I want one Storage dependency for persistent interaction data, so that configuration is consistent across modules.
2. As a library consumer, I want namespaces and keys to identify entries, so that unrelated data cannot collide.
3. As a library consumer, I want generated keys when creating a Session, so that I do not need to invent its identity.
4. As a library consumer, I want to supply a key when creating a message, so that its Neuron identity survives persistence.
5. As a library consumer, I want duplicate creation to leave the existing entry unchanged, so that insertion cannot accidentally replace data.
6. As a library consumer, I want get to return null for missing entries, so that absence is explicit.
7. As a library consumer, I want set to replace a complete value, so that intentional replacement has clear semantics.
8. As a library consumer, I want update to change only specified fields, so that unrelated fields remain intact.
9. As a library consumer, I want nested fields to be updated independently, so that separate metadata changes do not overwrite each other.
10. As a library consumer, I want field removal to differ from assigning null, so that both meanings can be represented.
11. As a library consumer, I want invalid updates to leave data unchanged, so that failed operations do not partially modify records.
12. As a library consumer, I want entries listed in insertion order, so that message and input sequences remain stable.
13. As a library consumer, I want replacement and update to preserve an entry's position, so that changing data does not reorder history.
14. As a library consumer, I want a list limit to select the most recent entries, so that I can request a bounded result.
15. As a library consumer, I want deletion and namespace clearing to be idempotent, so that cleanup can be retried.
16. As a file-storage user, I want a write to affect one entry rather than every conversation, so that growing histories do not require whole-history rewrites.
17. As a file-storage user, I want readers to observe complete values, so that concurrent writes do not expose partially written JSON.
18. As a file-storage user, I want independent concurrent field updates to survive, so that changing a title does not undo a last-use update.
19. As a file-storage user, I want persisted ordering to survive reopening Storage, so that a new process sees the same sequence.
20. As a Session owner, I want other users excluded from Session lookup and listing, so that my conversations remain isolated.
21. As a Session owner, I want Session creation to persist immediately, so that a newly selected Session can be looked up before its first message.
22. As a Session owner, I want empty Sessions hidden from listings, so that listings contain recognizable conversations.
23. As a Session owner, I want my Sessions listed by most recent use, so that I can resume the relevant conversation.
24. As a Session owner, I want title and metadata changes to preserve messages, so that changing Session information cannot erase conversation content.
25. As a Session owner, I want a cleared Session hidden again, so that listings reflect content that still exists.
26. As a Session owner, I want deleting a Session to remove its messages, so that deletion does not retain the conversation as accessible data.
27. As a Neuron integration user, I want message IDs and structured content preserved, so that replay, tools and pagination work after reopening a Session.
28. As a Neuron integration user, I want replayed message IDs ignored, so that retries do not duplicate messages or advance last-used time.
29. As a Neuron integration user, I want archived messages retained but excluded from active context, so that context trimming does not erase the saved conversation.
30. As a Neuron integration user, I want clear and summarization to keep working, so that the adapter preserves Neuron's history lifecycle.
31. As a direct Agent user, I want each newly persisted message to register Session use, so that Session dates do not depend on using Conversation.
32. As an application user, I want preferences isolated by user, so that another user's settings cannot replace mine.
33. As an application user, I want input history shared across my Sessions but isolated from other users, so that recall follows my own submissions.
34. As an application user, I want empty inputs and immediate repeats omitted from history, so that navigation remains useful.
35. As an application user, I want original command submissions retained, so that recalled input represents what I typed rather than a generated prompt.
36. As an application user, I want no automatic history truncation, so that older inputs remain available.
37. As an application user, I want stop requests visible across Storage instances, so that a running response can observe a request from another process.
38. As a maintainer, I want the same contract tested against memory and file adapters, so that their externally visible behavior cannot drift.
39. As a maintainer, I want outdated examples and architecture guidance updated with the redesign, so that consumers do not build against superseded behavior.

## Implementation Decisions

### Storage contract

- Keep one generic StorageInterface. Its public vocabulary is create, get, set,
  update, delete, list and clear. Do not add append to Storage: sequence-oriented
  modules retain their own append methods and implement insertion through create.
- An entry is identified by the exact namespace and key. Storage does not interpret
  user IDs, Session titles, message roles or application metadata.
- Values are JSON-compatible arrays. Validate inputs before changing persistent
  state. Memory and file adapters accept and reject the same values and identifiers.
  Returned and stored values must not share mutable PHP references with callers.
- create accepts a namespace, value and optional supplied key. Without a key it
  generates an opaque key; on success it returns the key. A supplied existing key
  produces a distinguishable duplicate-key conflict without modifying its value
  or position. Existence checking and publication are one atomic operation.
- get returns the value or null. Missing reads do not create storage state.
- set inserts a missing key or replaces the complete value of an existing key.
  Replacement preserves insertion position. set intentionally offers replacement
  semantics; stale complete values are not merged automatically.
- update modifies an existing entry atomically and never recreates a missing one.
  A missing target produces a distinguishable missing-entry failure. update takes
  explicit field assignments and field removals, not a caller callback.
- Field paths are lists of literal string segments. A segment containing a dot is
  a literal name, not an implicit separator. Nested object fields are supported;
  arbitrary indexed list editing is outside this operation's contract. Assigning
  an array or list as a field value replaces that field as a whole.
- Missing intermediate object fields may be created for assignments. Traversing
  an existing scalar or list fails. Removing an absent field is a no-op. Assigning
  null preserves a null-valued field; it never means removal.
- Reject empty paths and overlapping assignment/removal paths, including an
  ancestor and descendant in the same request. Validate and apply the entire
  request as one operation; any failure leaves the entry unchanged. An empty
  update of an existing entry is a no-op.
- list returns an ordered list of key/value pairs. Keys remain strings, including
  numeric-looking strings; PHP associative-array key coercion must not alter their
  identity. Storage bookkeeping is not exposed as caller metadata.
- list orders oldest insertion first. With a positive limit it returns the latest
  entries, still oldest first within that result. Zero returns an empty list;
  negative limits are invalid. Replacement and update never move an entry.
- delete removes a key if present. Deleting and recreating a key gives it a new
  insertion position. clear removes all entries of a namespace and is idempotent.
  Neither operation affects another namespace.
- Remove StoredDocument and document-metadata filtering. Domain data, including
  Session application metadata, belongs in values. Keep portable identifier
  validation and error behavior consistent across adapters.

### File and memory adapters

- FileStorage accepts a root and uses one namespace directory containing one
  complete JSON document per logical entry. The same layout applies to messages,
  Session records, preferences, history and stop signals. There are no JSONL
  namespaces, format selectors, prefix rules or compaction policies.
- An entry's internal envelope stores its value and insertion position. Namespace
  and key remain its logical identity. Bookkeeping is not part of the value.
- Assign positions using a persistent namespace-local counter protected by a
  stable namespace lock. Reserve a position before publishing a new entry;
  unused gaps are valid. Existing entries keep their positions. Keep coordination
  state outside the logical entry listing and preserve it when clearing entries.
- All mutations of a namespace participate in the same locking protocol, including
  create, set, update, delete and clear. update's lock covers read, modification
  and publication, not only the final write. Namespace-wide coordination also
  serializes position allocation and clear against new insertions.
- Publish complete entry documents through temporary files and atomic replacement
  within the namespace. A stable lock must not be the entry file being replaced.
  Duplicate creation cannot overwrite an existing entry. Clean up unpublished
  temporary documents after an ordinary failure.
- Coordinate get and list with mutations so readers see complete committed values.
  list returns a coherent namespace snapshot; coordination files and temporary
  files are excluded. Corrupt stored data produces a clear failure rather than
  being silently treated as an empty namespace.
- Preserve existing protections against invalid identifiers, path traversal and
  symbolic-link substitution. Reuse the existing file adapter's safe-publication
  behavior where compatible with the new locking contract.
- InMemoryStorage retains the same insertion, replacement, update, conflict and
  snapshot semantics using in-process state. Reopening FileStorage preserves both
  data and ordering without relying on modification times or directory order.
- Direct key access and supplied-key duplicate detection concern one entry.
  Listing scans and sorts the namespace. A limit bounds the returned result, not
  necessarily the amount of filesystem work. No persistent lookup index is added
  in this redesign.

### Session and Neuron integration

- SessionStore receives Storage and a user ID. Its default MessageStoreInterface
  adapter uses that same Storage; an explicitly supplied message store may remain
  available as an override. Do not require a second persistence dependency for
  the normal case or put Neuron message types into StorageInterface.
- Session records live in a namespace derived from the user ID. Message namespaces
  incorporate the owning user and Session identity. Lookup and deletion first
  establish ownership through the user's Session namespace; knowing another
  user's thread key does not grant access.
- A Session record contains title, createdAt, lastUsedAt, hasContent and application
  metadata. Only Session changes that record. SessionStore delegates record
  lifecycle operations to Session rather than introducing a SessionRecords layer.
- SessionStore.create immediately persists a record with hasContent false.
  SessionStore.get returns null for a key outside the user's namespace. Listing
  excludes records without content, applies the requested application-metadata
  filter within that user's records and sorts by lastUsedAt descending, with a
  deterministic key tie-breaker.
- Listing must not load or deserialize message histories. Remove the history-size
  field and accessor from SessionSummary and remove the byte-size display from
  ResumeCommand. Retain identity, title and last-use information.
- Session title and metadata mutations use atomic field assignments/removals.
  Separate nested metadata changes must not replace the complete metadata map.
- The message adapter implements Neuron's existing MessageStoreInterface. One
  message is one Storage entry keyed by its Neuron ID. It preserves serialized
  message fields and keeps its own archive state separate from message payload.
- append uses atomic create with the supplied message ID. Only a duplicate-key
  conflict is treated as an ignored replay; validation and I/O failures propagate.
  A duplicate must not replace payload, reset archive state or register Session use.
- Each successfully inserted message asks Session to register use, including
  insertion through an Agent used directly. Update lastUsedAt for that insertion.
  Set hasContent true for person-authored content; non-person messages must not
  reset an already true flag. Retain the existing Session content distinction
  between user content and tool-result messages.
- loadActive returns unarchived messages in insertion order. archive marks the
  oldest requested number of active messages without deleting them, changing
  payloads, moving their positions or registering new Session use. Non-positive
  archive counts are no-ops, preserving Neuron's behavior.
- loadAll includes archived messages and preserves Neuron's limit and before-ID
  behavior. An unknown before-ID returns no messages. Pagination remains a concern
  of the message adapter; it does not require a domain-specific Storage query.
- clear removes active and archived message entries and asks Session to reset
  hasContent. Reset and summarization must work when Neuron clears and subsequently
  re-appends messages. The existing command that starts a new Session continues to
  preserve the previous Session; it is distinct from clearing a message thread.
- Session deletion removes its message entries and record during normal execution.
  Preserve Agent-copy binding and its configured context window.

### Preferences, input history and stop signals

- ConfigurationStore keeps one preferences record per user in the preferences
  namespace. Preserve its existing typed fallback and JSON-validation behavior.
  Individual preference changes and removals use field updates. set may establish
  an initial empty record, but creation races must not reset existing preferences:
  use atomic create and distinguish a duplicate from a real failure.
- InputHistory receives Storage and a user ID. Each original submission is a
  separate entry in a user-specific namespace, shared across that user's Sessions.
  Its append method skips empty inputs and an immediate repeat, comparing content
  without the generated message ID. Use the latest entry for that comparison,
  then create the new entry. Preserve original command syntax and omit generated
  command prompts. There is no maximum size or automatic truncation.
- InputHistory.list accepts an optional limit and returns UserMessage objects in
  insertion order. Preserve the existing treatment of non-text content and
  reasoning-only input. Do not require message-history semantics for input recall.
- StopSignal stores a small requested value through set, checks it through get
  and removes it through delete. Preserve cross-instance polling and stop behavior.

### Concurrency and documentation

- Guarantee atomic individual Storage operations, duplicate insertion exclusion,
  complete-value publication and preservation of concurrent independent field
  updates. Concurrent replacement of the same field follows serialized last-write
  semantics. set remains a complete replacement, not a conflict-aware patch.
- Do not promise transactions across multiple entries or namespaces. Message
  insertion and Session-use registration, Session deletion, and batch archiving
  are multi-operation domain workflows. Failures between operations are surfaced;
  crash recovery and cross-record transactional repair are outside this scope.
- Atomic Storage operations do not make two independent ChatHistory instances on
  the same thread a coordinated conversation. The Host Application serializes
  overlapping turn, archive, clear and delete workflows for the same Session.
  The input-history last-entry comparison followed by insertion is likewise not
  guaranteed to deduplicate simultaneous identical submissions across processes.
- Update examples, public documentation, the domain vocabulary and release notes
  for the breaking interface and format. Replace the architectural decision that
  couples Session information and messages in one document. Preserve Session-owned
  mutation and Conversation-owned title-generation configuration.

## Testing Decisions

- The user confirmed StorageInterface as the shared contract-test seam for
  InMemoryStorage and FileStorage, with additional domain behavior tests through
  SessionStore, InputHistory and the adapter's MessageStoreInterface. Prefer these
  existing public seams; do not introduce production seams just for testing.
- Good tests assert observable values, identities, ordering, isolation, failure
  behavior and preserved updates. Do not test private helpers, lock filenames,
  counter serialization or a particular internal envelope representation.
- Rework the existing InMemoryStorageTest and FileStorageTest contract coverage:
  generated and supplied keys, missing reads, namespace isolation, complete
  replacement, idempotent deletion, identifier validation, exact key identity
  and detached snapshots remain relevant. Metadata-filter and StoredDocument-size
  assertions are superseded by the new contract.
- Add contract scenarios for position preservation, delete-and-recreate ordering,
  limited lists, clear isolation, nested assignments, removal versus null,
  missing update targets, invalid paths and all-or-nothing update failure.
- Extend the existing FileStorageTest subprocess tests for competing supplied-key
  creation and concurrent readers/deleters. Add independent concurrent top-level
  and nested updates, create-versus-clear serialization, and reopening after
  writes. Use explicit worker coordination rather than timing assumptions.
- Through public Storage operations, verify updating one entry preserves other
  entries and namespaces. Do not claim throughput or latency improvements without
  a separate measurement on representative filesystem data.
- Reuse SessionMessageStoreTest coverage for archived history, idempotency,
  pagination, structured tool results, ownership and Agent binding. Add ignored
  duplicate last-use behavior, direct-Agent registration, clear visibility and
  clear/reappend summarization behavior using Neuron's public integration seam.
- Rework SessionStoreTest, SessionTest and SessionSummaryTest to verify immediate
  creation, empty-session visibility, content tracking, ordering, metadata filters,
  title preservation, nested updates and absence of history-size semantics.
  Verify listing does not consult the message store through an observable test
  double at MessageStoreInterface, not by testing an internal file routine.
- Reuse ConfigurationStoreTest for typed fallbacks and user isolation, adding
  independent preference updates and safe initial-record creation. Reuse
  InputHistoryTest and ConversationInputHistoryTest for original input capture,
  empty-input skipping, repeat comparison, per-user isolation and limited recall.
- Reuse StopSignalStorageTest and stop integration tests to ensure cross-instance
  signals survive the Storage contract change. Rework ResumeCommandTest to verify
  listing remains useful without a byte-size label.
- After PHP changes, run the repository's formatter, static analysis and relevant
  PHPUnit tests. Before pushing or releasing, run the complete test and formatting
  checks. Keep broad unrelated formatting changes separate.

## Out of Scope

- Compatibility shims for the old Storage methods, document metadata or format.
- Migration, automatic legacy backfill and preservation of legacy-derived IDs.
- JSONL, event logs, compaction, per-namespace format configuration and automatic
  selection of physical formats based on domain names or method usage.
- A second general-purpose Storage interface or a Session-specific catalog.
- Database adapters, SQLite adoption and remote persistence in this feature.
- Persistent filesystem query indexes, pagination optimizations, benchmark-based
  performance guarantees and a maximum input-history size.
- History byte sizes, message counters and title-generation policy changes.
- Arbitrary list-element patching, arithmetic update expressions and callbacks
  executed inside the Storage critical section.
- Cross-record transactions, concurrent multi-Agent turn coordination, automatic
  repair after partial domain workflows and guaranteed power-loss durability.

## Further Notes

- This spec supersedes the earlier storage handoff and its HTML explanation where
  they propose JSONL namespaces, Storage-provided Neuron stores, or accepted
  read-modify-write losses on the same record. The final design is one JSON file
  per entry, with the Neuron adapter using the generic Storage.
- The redesign intentionally supersedes ADR-0001, "Store Session listing
  information with its history." That decision describes one document containing
  messages and Session information, whereas this feature separates them. Its
  no-migration decision remains consistent with the user's request. ADR-0002's
  Conversation-owned title-generation configuration remains applicable.
- Atomic field updates prevent independent field changes from overwriting each
  other. They do not prevent an intentional concurrent complete set from replacing
  those changes, or make a sequence of domain operations transactional.
- A file per message trades whole-conversation rewrites for more file operations
  during reading. This is an accepted design tradeoff, not a measured claim that
  every workload is faster. Keep the public contract independent of future storage
  optimizations.
- This ticket specifies the feature only. Publishing it does not start implementation,
  change the working branch, install dependencies or alter production data.
