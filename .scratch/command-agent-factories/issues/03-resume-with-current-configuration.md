# 03: Resume saved Sessions through the registry and retire argument-free reconstruction

**What to build:** Resume by key and by selection work in the backend and TUI using a new Agent built from the latest saved general configuration. Resuming preserves the chosen History and no longer relies on recreating the old Agent's class without its application settings.

**Blocked by:** 02 — Clear a conversation with the saved Agent configuration in backend and TUI.

**Status:** resolved

**Parent:** [Coordinated specification](../spec.md)

- [x] Resume with a key reads the Session from the supplied user-scoped SessionStore and preserves the existing warning behavior when it is missing, without constructing or activating another Agent.
- [x] For an existing Session, Resume reads the latest global Configuration, asks AgentFactoryRegistry for a fresh Agent, assigns the selected Session as History and calls useAgent. It does not save unchanged configuration or restore settings from the old Session.
- [x] Resume without arguments preserves the existing list, labels, descriptions, empty-list feedback and SelectionRequest protocol. It does not construct an Agent merely to display a choice.
- [x] A later selection invocation receives the selected value unchanged, rereads availability and configuration, and uses live runtime with the same registry and Store dependencies. Updated settings between the two invocations are respected.
- [x] The TUI presents the activated History and later Turns use the activated Agent. Merely replacing an Agent while retaining the same History does not unnecessarily reset notices or conversation presentation.
- [x] Missing configuration, unknown factory and preparation exceptions leave the previously active Agent usable and report failure through the established dispatch/completion path. No stronger persistence rollback is claimed.
- [x] Clear and Resume work after an actual initial Turn and through a new-Session then prior-Session cycle using deterministic providers, required constructor dependencies and setter-driven settings. Assert actual subsequent responses and History/thread identity.
- [x] Test both Commands::run with the backend example and Tui::run with VirtualTerminal, including selection follow-up and refusal during accepted/active Turns.
- [x] Remove newAgent from the controls contract and all implementations, callers, examples and obsolete tests in both repositories. No current-class make fallback, compatibility implementation or implicit Agent clone remains for reconstruction.
- [x] Preserve useAgent, live agent access, existing Session identity behavior, opt-in Commands, first-match dispatch and Input history.
- [x] Complete documentation and ADR descriptions of registry-based Clear/Resume and shared controls, while preserving void Command results and two-step selections.
- [x] Both repositories' relevant suites and static analysis pass together against the same contract revision, including tests previously covering the retired reconstruction behavior.

## Answer

Resume now checks the selected user-scoped Session, reads current saved global
configuration and prepares a fresh registry-created Agent before activation.
Removed argument-free reconstruction from both adapters and the shared contract.
TUI preserves presentation when the exact same History is retained. Examples show
required constructor dependencies and setter-applied configuration.

Backend verification: 185 tests / 737 assertions, full PHPStan and six runnable
examples. TUI verification against this shared revision: 223 tests / 1019
assertions, full PHPStan. Public-seam regressions cover initial Turns, Clear,
deferred Resume with changed settings, thread identity, preparation failures with
subsequent answers, selection availability, admission and Input history.
