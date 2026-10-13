# 02: Persist entries on files and move StopSignal to the new Storage

**What to build:** A stop request written by one Storage instance can be observed
and cleared by another process using the new FileStorage. The same ordered entry
contract also survives reopening the file adapter and concurrent field changes.

**Blocked by:** 01: Expand the Storage contract and make it usable in memory.

**Status:** completed

- [x] Implement the new file adapter against the contract from ticket 01 and run the shared contract tests against it. Keep the existing adapter available for consumers not yet migrated.
- [x] Use one complete JSON document per entry in a namespace directory, uniformly for all data. No global data document, JSONL, format selector or compaction is introduced.
- [x] Persist an insertion position separately from the caller's value. A namespace-local counter and stable locking assign unique positions; gaps are allowed and replacements preserve their position.
- [x] Keep lock/counter coordination state outside logical listings and preserve it when clear removes entries. Reopening preserves ordering independently of names, modification times and directory enumeration order.
- [x] Coordinate every namespace mutation, including position allocation, duplicate creation, update, deletion and clear, through the same stable lock protocol. update protects the complete read-modify-publish operation.
- [x] Publish complete documents with temporary-file preparation and atomic publication/replacement. Supplied-key creation cannot replace an existing entry; readers never see partially published JSON.
- [x] get and list coordinate with mutations; list returns a coherent namespace snapshot and excludes internal or temporary files. Corrupt data produces an explicit error rather than an empty result.
- [x] Preserve invalid-identifier, traversal and symbolic-link protections. Clean up unpublished temporary documents after ordinary failures.
- [x] A direct read or supplied-key duplicate check targets one entry. Listing may scan and sort the namespace, even with a limit; do not add an index or claim unmeasured performance gains.
- [x] Migrate StopSignal request, observation and clearing to set, get and delete on the new contract, preserving polling behavior and namespace isolation in memory and on files.
- [x] Extend the existing Storage subprocess tests with competing creates, independent top-level/nested updates, readers during replacement, reopening, and clear-versus-create serialization. Coordinate workers explicitly rather than relying on sleeps.
- [x] Verify a cross-process stop request through StopSignal's public behavior and preserve existing stop integration tests.
- [x] Run formatting, static analysis and relevant tests; both the migrated stop flow and unmigrated consumers remain green.

## Notes

Follow the parent spec's individual-operation atomicity and durability limits.
Do not promise transactions across entries or guaranteed recovery from power loss.

## Implementation handoff

`FileEntryStorage` implements the transitional `EntryStorageInterface`, with the
same constructor shape as the legacy `FileStorage` (`string $root`). The legacy
file adapter remains unchanged for unmigrated consumers. Every namespace has a
stable lock shared by all reads and mutations; positions are reserved in a
persistent namespace-local counter before publishing each new entry. Entry JSON
contains insertion position and value, and complete documents are published from
same-directory temporary files. Logical entry lists exclude coordination state.

`StopSignal` now receives `EntryStorageInterface` and uses set/get/delete.
Integration tests and the interruption example use the transitional memory
adapter for stop signals until ticket 07 establishes the final public names.

`FileEntryStorageTest` extends the shared contract suite and adds reopening,
namespace/entry symbolic-link refusal, corruption failures, preservation of
unrelated entries, and pipe-coordinated subprocess races. `StorageWorker` uses
READY acknowledgements and command/result messages; concurrency tests exercise
public operations and accept both valid serialized clear/create outcomes.

Validation passed: `composer cs:fix`, `composer stan`, file contract/concurrency
suite (22 tests, 374 assertions), interruption suite (15 tests, 1040 assertions),
and complete PHPUnit suite (285 tests, 2931 assertions).
