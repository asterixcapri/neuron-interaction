# 01: Expand the Storage contract and make it usable in memory

**What to build:** Consumers can use the new ordered, namespaced entry contract
with InMemoryStorage while existing interaction flows continue working during the
migration. This is the expand step of a breaking shared-interface refactor, not a
permanent compatibility feature.

**Blocked by:** None (can start immediately).

**Status:** ready-for-agent

- [ ] Introduce the new Storage contract alongside the old form using clearly distinct transitional types; do not try to overload incompatible old and new methods. Existing consumers and tests continue to work. Ticket 07 removes the temporary form and establishes the final public names.
- [ ] Implement create, get, set, update, delete, list and clear in memory. Do not add append, document metadata, metadata queries or StoredDocument to the new contract.
- [ ] create generates an opaque key when omitted and returns it; a supplied existing key produces a distinguishable duplicate-key conflict without replacement. Missing get returns null without creating state.
- [ ] set inserts or replaces a complete value, preserving the original insertion position on replacement. Deleting and recreating a key gives it a new position.
- [ ] list returns ordered key/value pairs with exact string key identity, including numeric-looking keys. A positive limit selects the latest entries in insertion order; zero returns no entries and negative limits fail.
- [ ] delete and clear are idempotent and isolated by namespace. Returned values and stored values are detached from mutable caller references.
- [ ] update operates only on existing entries and accepts explicit assignments and removals through literal string-segment paths, including nested object fields. It takes no caller callback.
- [ ] Missing intermediate object fields can be created for assignment. Scalar/list traversal, empty paths and overlapping paths fail without any change. Removing an absent field is a no-op, while assigning null retains the field. Whole arrays/lists can be assigned as field values; indexed list editing is not supported.
- [ ] A missing update target produces a distinguishable failure and is never recreated. An empty update of an existing entry is a no-op.
- [ ] Validate JSON-compatible values and portable identifiers before accepting state. Define error distinctions that FileStorage and the message adapter can reuse without masking I/O errors.
- [ ] Add a reusable public-contract test suite covering these behaviors, using the existing memory Storage tests as prior art. Test public outcomes rather than private helpers or transitional naming.
- [ ] Run formatting, static analysis and relevant tests. Existing interaction tests remain green with the legacy form; no consumer is forced onto an incomplete new adapter.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
Keep temporary coexistence limited to the migration. Do not add legacy-data
migration, compatibility guarantees or a second permanent Storage interface.
