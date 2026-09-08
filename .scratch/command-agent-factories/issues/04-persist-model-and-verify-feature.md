# 04: Keep a selected model through Clear, Resume and demo restart

**What to build:** A person changes the model in the TUI demo without losing the current History, then clears, resumes and restarts with that saved choice intact. This final vertical slice delivers and verifies the complete feature across both repositories, including failure recovery and the backend's consumption of the same saved configuration.

**Blocked by:** 03 — Resume saved Sessions through the registry and retire argument-free reconstruction.

**Status:** ready-for-agent

**Parent:** [Coordinated specification](../spec.md)

- [ ] Demo ModelCommand uses the common run method and adapter accessors for current Agent, AgentFactoryRegistry and ConfigurationStore. Demo-specific model/capability choices remain in application code, not the registry or a universal built-in ModelCommand.
- [ ] With no identifier, preserve the model SelectionRequest and follow-up invocation. With an identifier, validate against the application's available models before changing saved settings or the active Agent.
- [ ] Read a fresh global Configuration, change only its model choice, and preserve the selected agent and unrelated fields. Build the candidate through the registered factory and assign the current Agent's History before saving.
- [ ] Save successfully prepared configuration before useAgent and present the change afterward. Subsequent eligible Turns use the activated Agent; no change is applied to an already-running Turn.
- [ ] Validation, construction or History-assignment failures do not save or activate the candidate. A save exception prevents activation; do not assert unsupported rollback if a Storage implementation writes before throwing.
- [ ] The demo restarts from existing saved configuration without overwriting it with defaults. Initial construction and later Command construction use the same registered factory/configuration contract and explicit startup Session selection.
- [ ] Prove the complete scenario through public TUI composition: initial Turn, model selection, continued response on the same History, Clear, response on the new Session, Resume of the earlier Session, and another response retaining the selected model and setter-driven capability.
- [ ] Reopen the persisted configuration/Session stores in a fresh composition and verify that construction still uses the saved choice. The test remains local and deterministic, without remote model calls.
- [ ] Through the backend example, execute the same shared Session Commands against the saved configuration and verify equivalent construction semantics with backend-specific output.
- [ ] Verify the interaction remains usable after rejected settings or failed preparation, and that notices/History, deferred selections, admission policy and Input history retain their documented behavior.
- [ ] Run the relevant full PHPUnit and PHPStan suites for both repositories, backend examples and applicable demo checks against one coordinated dependency revision. Fix feature-caused regressions instead of reporting one repository complete independently.
- [ ] Final examples, glossary and migration guidance consistently describe CommandControlsAdapterInterface, registry/configuration bootstrap, removal of newAgent and saving runtime choices that must survive reconstruction. Do not introduce discarded architectural alternatives.
- [ ] Dependency adjustments consume the new shared contract only; preserve unrelated uncommitted migration work and avoid broad updates.

The dependency on ticket 03 is required by this ticket's complete Model → Clear → Resume acceptance path, not by the isolated model-selection code. This is a final behavior slice with integrated verification, not a separate testing-only task.
