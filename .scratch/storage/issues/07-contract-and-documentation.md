# 07: Complete the Storage transition and update project guidance

**What to build:** Library consumers use only the final Storage interface and
adapters, with consistent examples and documentation. The legacy contract and
document model are gone, and the complete interaction feature set is verified.

**Blocked by:** 02: Persist entries on files and move StopSignal to the new Storage; 03: Persist isolated user preferences without lost independent updates; 04: Record one entry per input in user-isolated InputHistory; 05: Save Sessions and Neuron messages as separate entries in one Storage; 06: List and resume Sessions without reading conversation histories.

**Status:** ready-for-agent

- [ ] Finish the contract step: remove the legacy Storage interface/adapters, transitional types, temporary compatibility branches and obsolete tests after every consumer has migrated.
- [ ] Establish the final public StorageInterface, InMemoryStorage and FileStorage names consistently across consumers and tests. Remove StoredDocument, document metadata filtering and the old read/write/entries vocabulary.
- [ ] Verify no production caller or example relies on the removed interface, StoredDocument, Session history size or a second mandatory message-persistence dependency.
- [ ] Update public examples and documentation to configure one Storage and user-isolated SessionStore, ConfigurationStore and InputHistory. Explain create conflicts, partial nested update/removal, exact ordering and clear semantics.
- [ ] Document one JSON file per entry, the cost of namespace scans, bounded results versus bounded work, and atomic single-operation guarantees. Do not advertise JSONL, compaction, transactions across records or unmeasured throughput gains.
- [ ] Supersede ADR-0001's coupling of Session information and messages in one document. Update SessionSummary's domain description to remove history size. Preserve ADR-0002's Conversation-owned title-generation configuration.
- [ ] Follow the project's release-note conventions to explain the breaking interface and data-format change and the explicit absence of migration or automatic legacy backfill. Do not create or publish a release in this ticket.
- [ ] Verify the complete supported flow on files and in memory: preferences, input capture, Session creation and resume, message persistence, archive, reset/clear, title/metadata updates and stopping a response.
- [ ] Run formatting fixes, static analysis, relevant tests, the full repository test suite and the formatting check. Resolve failures without suppressing analysis errors or broad unrelated formatting edits.
- [ ] Confirm the final result contains one general-purpose Storage interface, no Storage append method, no persistent query index and no unfinished transitional branches.
- [ ] Leave the parent spec's status and contents unchanged; ticket completion does not close or modify the parent issue.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
Temporary coexistence was an implementation sequencing technique, not a shipped
compatibility promise. This ticket neither migrates existing data nor implements
a database adapter, deploys changes or publishes a release.
