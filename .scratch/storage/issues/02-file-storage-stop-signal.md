# 02: Persist entries on files and move StopSignal to the new Storage

**What to build:** A stop request written by one Storage instance can be observed
and cleared by another process using the new FileStorage. The same ordered entry
contract also survives reopening the file adapter and concurrent field changes.

**Blocked by:** 01: Expand the Storage contract and make it usable in memory.

**Status:** ready-for-agent

- [ ] Implement the new file adapter against the contract from ticket 01 and run the shared contract tests against it. Keep the existing adapter available for consumers not yet migrated.
- [ ] Use one complete JSON document per entry in a namespace directory, uniformly for all data. No global data document, JSONL, format selector or compaction is introduced.
- [ ] Persist an insertion position separately from the caller's value. A namespace-local counter and stable locking assign unique positions; gaps are allowed and replacements preserve their position.
- [ ] Keep lock/counter coordination state outside logical listings and preserve it when clear removes entries. Reopening preserves ordering independently of names, modification times and directory enumeration order.
- [ ] Coordinate every namespace mutation, including position allocation, duplicate creation, update, deletion and clear, through the same stable lock protocol. update protects the complete read-modify-publish operation.
- [ ] Publish complete documents with temporary-file preparation and atomic publication/replacement. Supplied-key creation cannot replace an existing entry; readers never see partially published JSON.
- [ ] get and list coordinate with mutations; list returns a coherent namespace snapshot and excludes internal or temporary files. Corrupt data produces an explicit error rather than an empty result.
- [ ] Preserve invalid-identifier, traversal and symbolic-link protections. Clean up unpublished temporary documents after ordinary failures.
- [ ] A direct read or supplied-key duplicate check targets one entry. Listing may scan and sort the namespace, even with a limit; do not add an index or claim unmeasured performance gains.
- [ ] Migrate StopSignal request, observation and clearing to set, get and delete on the new contract, preserving polling behavior and namespace isolation in memory and on files.
- [ ] Extend the existing Storage subprocess tests with competing creates, independent top-level/nested updates, readers during replacement, reopening, and clear-versus-create serialization. Coordinate workers explicitly rather than relying on sleeps.
- [ ] Verify a cross-process stop request through StopSignal's public behavior and preserve existing stop integration tests.
- [ ] Run formatting, static analysis and relevant tests; both the migrated stop flow and unmigrated consumers remain green.

## Notes

Follow the parent spec's individual-operation atomicity and durability limits.
Do not promise transactions across entries or guaranteed recovery from power loss.
