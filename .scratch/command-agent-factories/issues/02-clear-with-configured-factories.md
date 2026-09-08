# 02: Clear a conversation with the saved Agent configuration in backend and TUI

**What to build:** A Host Application registers construction once and supplies its saved configuration; executing Clear through either the backend example or the TUI creates a new Session with a fresh Agent that retains required dependencies, the configured model and setter-driven capabilities. The previous Session stays available.

**Blocked by:** 01 — Preserve Command execution through the renamed controls adapter.

**Status:** ready-for-agent

**Parent:** [Coordinated specification](../spec.md)

- [ ] AgentFactoryRegistry registers closures by non-empty stable identifiers and creates an Agent from Configuration's agent field. It rejects duplicate registrations and missing, invalid or unknown identifiers without falling back to a class name or argument-free constructor.
- [ ] Each factory receives a detached Configuration and is responsible for a fresh Agent, required dependencies, custom setters and validation of its application fields. Preserve factory exceptions and enforce the Agent return contract.
- [ ] The registry does not assign History, access either Store, activate Agents or know individual Commands. No Agent cloning, reflection or live-instance configuration extraction is introduced.
- [ ] CommandControlsAdapterInterface exposes agentFactoryRegistry and configurationStore, with all concrete/test adapters in both repositories updated. Preserve all existing controls and their responsibilities.
- [ ] Public TUI construction and make accept the registry and ConfigurationStore without reordering existing arguments. Optional empty/in-memory defaults remain stable during the TUI run and never infer a factory or configuration from the initial Agent.
- [ ] Adapters created for ordinary or deferred invocations receive the same supplied registry and Store instances. agent continues to read the runtime's current Agent; useAgent only activates the prepared instance and presents its History.
- [ ] Clear reads the general global Configuration, constructs through the registry before creating the new Session, assigns the new History to that fresh Agent, and activates it. It preserves the previous Session and does not resave unchanged configuration.
- [ ] Missing general configuration and construction or History-assignment failures use the ordinary Command failure path and do not replace the old Agent. Do not claim rollback of a Session created before a later failure.
- [ ] A custom Command can use registry and Store access through its run adapter without receiving those dependencies in its constructor or adding new control verbs.
- [ ] Bootstrap the backend examples and TUI demo with registered factories and global configuration initialized only if missing. Construct their initial Agent through that same path and retain the explicitly chosen startup History.
- [ ] Existing plain TUI usage, opt-in Commands, initial external History, Input history and user-scoped Store behavior remain intact. Adapt reconstruction fixtures to supply explicit factory/configuration rather than inventing fallback settings.
- [ ] Demonstrate Clear through Commands::run in the backend and through Tui::run with VirtualTerminal using real stores and a deterministic provider. Include an Agent that has already answered, a required constructor dependency and a setter-controlled option, and verify a subsequent configured response on the new Session.
- [ ] Add focused registry registration/selection error tests, including isolated configuration input. Avoid a new registry interface solely for mocking.
- [ ] Update setup and Clear examples/documentation and run the relevant suites/static analysis in both repositories. The existing Resume implementation and its newAgent control remain temporarily until ticket 03; no final release is made with that transitional behavior.
