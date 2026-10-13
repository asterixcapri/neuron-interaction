# 04: Record one entry per input in user-isolated InputHistory

**What to build:** A user can recall original submissions across their Sessions
in insertion order, without sharing history with other users or rewriting a
growing list for every submission.

**Blocked by:** 02: Persist entries on files and move StopSignal to the new Storage.

**Status:** ready-for-agent

- [ ] Make InputHistory receive the new Storage and a user ID. Derive its namespace from that user and persist each input as a distinct entry through create.
- [ ] Keep InputHistory.append as a domain operation, without adding append to Storage. Input history remains independent from the message namespaces of individual Sessions.
- [ ] Capture original submitted UserMessage data, including command syntax. Do not record command-generated prompts as authored submissions.
- [ ] Preserve the current empty-input and reasoning-only distinctions, including meaningful non-text inputs.
- [ ] Read the most recent input for immediate-repeat comparison and compare serialized authored data without the generated message ID. Preserve repeated inputs separated by another submission.
- [ ] Support InputHistory.list with an optional limit, returning UserMessage objects in insertion order. Zero returns no inputs and invalid negative limits fail consistently.
- [ ] Keep history unbounded; do not add retention, truncation, JSONL or an index. Document that a bounded result may still require scanning the namespace.
- [ ] Integrate the new constructor and behavior with Conversation's input-history configuration and directly affected callers/examples, without changing what counts as an original submission.
- [ ] Reuse InputHistoryTest and ConversationInputHistoryTest to verify command capture, structured inputs, empty skipping and repeat detection on memory and file adapters.
- [ ] Verify same-user cross-Session recall, isolation between users, limited results and persistence across new file-adapter instances.
- [ ] State the existing workflow limit: last-entry comparison and subsequent insertion are not a transaction, so simultaneous identical submissions are not guaranteed to deduplicate.
- [ ] Run formatting, static analysis and relevant tests; preference and Session migrations are not prerequisites.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
This slice preserves submitted input; it does not implement Neuron message
archiving or Session listing.
