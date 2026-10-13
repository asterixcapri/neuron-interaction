# 03: Persist isolated user preferences without lost independent updates

**What to build:** Each user can read, change and remove individual preferences
without replacing another user's preferences or unrelated fields in their own
record. Existing typed fallback behavior remains available.

**Blocked by:** 02: Persist entries on files and move StopSignal to the new Storage.

**Status:** ready-for-agent

- [ ] Migrate ConfigurationStore to the new Storage and the get, set, delete and list domain vocabulary.
- [ ] Keep one preferences record per user under a deterministic user-derived key. User identities compare exactly and do not rely on document metadata filtering.
- [ ] Preserve the existing fallback-selected PHP types, non-empty string rules, supported array values and JSON-validation failures.
- [ ] Establish a missing preferences record with atomic create. Catch only a duplicate-key conflict during competing initialization; never reset an existing record to an empty value.
- [ ] Persist changes and removals through atomic field updates. A preference name is a literal field segment, so dots in a name do not accidentally create nesting.
- [ ] Distinguish removal from assigning null, preserve unrelated preferences, and return detached snapshots.
- [ ] Preserve behavior for missing preferences and idempotent removal without creating unnecessary state on reads.
- [ ] Test ConfigurationStore's public behavior against memory and file adapters, reusing ConfigurationStoreTest's typed-fallback and user-isolation cases.
- [ ] Verify competing initialization and independent preference changes retain both values on FileStorage without testing internal lock or file names.
- [ ] Update the directly affected preference consumers and examples so the slice is usable on its own; reserve repository-wide documentation cleanup for ticket 07.
- [ ] Run formatting, static analysis and relevant tests, preserving unmigrated Session and input-history behavior.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
This ticket does not change preference policy or introduce legacy-data migration.
