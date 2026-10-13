# 05: Save Sessions and Neuron messages as separate entries in one Storage

**What to build:** A user can create a Session, bind an Agent, save and reopen its
messages, update its title and metadata, archive context and clear or delete the
conversation through one Storage dependency. Replayed messages do not replace
existing content or register new Session use.

**Blocked by:** 02: Persist entries on files and move StopSignal to the new Storage.

**Status:** ready-for-agent

- [ ] Migrate SessionStore and Session to the new Storage. Default message persistence uses a MessageStoreInterface adapter over that same Storage; an explicit optional message-store override may remain supported.
- [ ] Persist a Session record immediately on creation with title, createdAt, lastUsedAt, hasContent and application metadata. The initial hasContent value is false.
- [ ] Use user-derived Session namespaces and message namespaces incorporating both the owning user and Session identity. Lookup and deletion establish ownership; another user's thread key cannot grant access.
- [ ] Make Session the sole owner of mutations to its record. Use atomic field updates for title, dates and nested metadata changes/removals rather than replacing the complete record or metadata map.
- [ ] Store one entry per message using its Neuron ID. Preserve all serialized content and metadata, including structured tool results, with archive state kept outside the message payload.
- [ ] Implement append through atomic create. Treat only a duplicate-key conflict as an ignored replay; other failures propagate. A replay preserves payload, archive state, position and Session last-use information.
- [ ] After a successful insertion, ask Session to register use. Update lastUsedAt for each new message, including direct Agent use, and set hasContent true for person-authored content. Other messages never reset an already true flag.
- [ ] Implement loadActive and loadAll in insertion order; preserve Neuron's limit/before-ID behavior and return no results for an unknown cursor. Archived entries remain available through loadAll and Session.getMessages.
- [ ] Implement archive by marking the oldest requested number of active entries without removing or moving them, changing payloads or registering new use. Non-positive counts remain no-ops.
- [ ] Implement clear for active and archived messages and reset the Session's hasContent. Verify Agent reset and summarization's clear/reappend workflow. Preserve the separate command behavior that starts a new Session without erasing the previous one.
- [ ] Session deletion removes the owned message entries and record during ordinary execution. Preserve Agent-copy binding and its configured context window.
- [ ] Keep current Session listing and ResumeCommand consumers functional during this migration; their final record-only listing and removal of history-size presentation belong to ticket 06. Do not leave public message-adapter methods unimplemented to defer lifecycle behavior.
- [ ] Reuse SessionMessageStoreTest and Session/Conversation tests for identity, ownership, archive behavior, pagination, tool-result round trips and Agent binding. Run the public lifecycle on both adapters.
- [ ] Add regressions for duplicate last-use behavior, independent title/metadata/date updates, direct-Agent registration, clearing content state and reset/summarization persistence.
- [ ] Document and preserve the scope of concurrency: individual Storage calls are atomic, but the Host Application serializes overlapping turns, archive, clear and delete workflows on the same Session. No cross-record transaction or crash-repair promise is introduced.
- [ ] Run formatting, static analysis and relevant tests. Update directly affected callers so creating, continuing and reopening a Session is demonstrable independently of preferences or input-history migration.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
No Session-specific catalog, SessionRecords layer, separate mandatory persistence
dependency or legacy-message migration is introduced.
