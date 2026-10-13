# 06: List and resume Sessions without reading conversation histories

**What to build:** A user sees their non-empty Sessions ordered by last use and can
resume one without loading every saved conversation for the listing. The listing
shows identifying information without a byte-size label.

**Blocked by:** 05: Save Sessions and Neuron messages as separate entries in one Storage.

**Status:** ready-for-agent

- [ ] Build SessionStore.list from the user's Session records only. Do not retrieve or deserialize messages to determine title, visibility, filters or summary information.
- [ ] Exclude hasContent false records. A newly created Session is hidden until it acquires person-authored content, archived conversations remain visible, and cleared Sessions become hidden again.
- [ ] Preserve exact application-metadata filtering within the owning user's namespace. A listing cannot enumerate another user's records.
- [ ] Sort by lastUsedAt descending, using a deterministic key tie-breaker. Do not change Storage's insertion-order contract to achieve Session-specific ordering.
- [ ] Remove the history-size field/accessor from SessionSummary and remove the byte-size label from ResumeCommand. Retain identity, title and last-use information and existing selection/resume behavior.
- [ ] Verify creating, using, archiving, clearing, listing and resuming Sessions end to end against both adapters.
- [ ] Use a test double at MessageStoreInterface to prove listing does not consult histories. Do not inspect private file routines or assert a particular directory layout.
- [ ] Rework SessionStoreTest, SessionSummaryTest and ResumeCommandTest for the new summaries, metadata filtering, visibility and ordering. Preserve unrelated command presentation behavior.
- [ ] Update directly affected examples and callers; avoid deleting generic size-formatting behavior that still has unrelated consumers.
- [ ] Run formatting, static analysis and relevant tests.

## Notes

Follow the parent spec, Ordered key-value Storage with one JSON file per entry.
This ticket removes byte-size semantics; it does not replace them with a message
counter or change automatic title-generation policy.
