# Store Session records and messages separately

A Session owns a small user-scoped record containing its title, creation and
last-use times, content flag and application metadata. Its Neuron messages are
separate ordered entries in the same generic Storage, keyed by message identity.
This supersedes [ADR-0001](0001-store-session-summary-with-history.md): listing
reads recognition data without deserializing histories, and a message insertion
or a field update changes only the affected entry.

FileStorage keeps one complete JSON file per entry, with namespace coordination
and atomic publication. A file per message costs more file operations when
reading history; no persistent query index, JSONL or compaction is introduced.
List limits bound results but may still require scanning and sorting a namespace.
This is a persistence tradeoff, without a measured performance guarantee.

Session retains responsibility for its record mutations, while its default
MessageStoreInterface adapter uses the same Storage. Atomic updates preserve
independent fields; complete set remains intentional replacement. Message
insertion and Session-use registration, deletion and batch archiving are separate
operations, without cross-record transactions or crash repair. The Host
Application coordinates overlapping workflows for a Session. Input-history
comparison followed by insertion likewise cannot guarantee concurrent deduplication.

The API and format target newly stored state. Existing documents are neither
migrated nor automatically backfilled. Conversation continues to own title-generation
configuration as described in [ADR-0002](0002-configure-session-title-generation-on-conversation.md).
