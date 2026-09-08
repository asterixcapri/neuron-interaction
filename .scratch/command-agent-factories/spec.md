# Configured Agent reconstruction across Neuron Interaction and Neuron TUI

Status: resolved
Repositories: neuron-interaction, neuron-tui
Feature: command-agent-factories
Scope: one coordinated implementation across both repositories.
TUI tracker reference: [neuron-tui](../../../neuron-tui/.scratch/command-agent-factories/spec.md)

## Problem Statement

A Host Application gives an interaction environment an Agent configured through its constructor and arbitrary setters. With Neuron AI 4, an Agent already associated with one conversation cannot safely be repurposed for another by assigning that conversation's History. Clear and Resume therefore need a fresh Agent.

The current migration delegates reconstruction to the Command Adapter, which calls the current Agent class's make method without arguments. That fails for required constructor arguments and discards instance configuration, including the model selected by the person and capabilities enabled at runtime. Implementing the same workaround in a backend repeats the problem.

Commands already have a useful invocation protocol and are mounted as instances. Host Applications should not need to inject registry, configuration and session dependencies into every Command. They need those modules available through one Command controls adapter, while retaining arbitrary Command behavior and application-specific construction of Agents.

In the TUI demo, selecting a model currently prepares another Agent but does not make the choice a saved source for subsequent reconstruction. The change must cover the shared package, terminal composition and demo together; updating only one repository leaves the feature incomplete.

## Solution

Introduce AgentFactoryRegistry in Neuron Interaction. The Host Application registers factories under stable identifiers. A factory receives Configuration and constructs a fresh configured Agent using the constructor, dependencies and setters known to that application. The general saved Configuration identifies the factory through its agent field; other fields belong to the application.

Rename the shared Command Adapter contract to CommandControlsAdapterInterface. Keep the existing Command invocation and dispatch protocol, exposing AgentFactoryRegistry and ConfigurationStore alongside the current Agent, SessionStore, mounted Commands and presentation controls.

ClearCommand and ResumeCommand read the current saved general Configuration, ask the registry for a new Agent, assign the desired Session as its History and activate it through useAgent. Commands retain ownership of these operations. No Interaction, ActiveAgent, replaceAgent operation or interface listing individual Command use cases is introduced.

Implement the shared contract and Session Commands in neuron-interaction, including its backend example. Extend neuron-tui composition to supply the registry and ConfigurationStore to each invocation. Update the demo to construct its initial Agent through the same factory/configuration path and persist model selection before activating the prepared Agent with the current History. This is one feature with coordinated changes in two repositories, governed by this specification.

## User Stories

1. As a Host Application author, I want to register an Agent factory under a stable identifier, so that saved configuration can select an Agent without storing a PHP class name.
2. As a Host Application author, I want a factory to receive Configuration, so that it can apply the current saved settings on every construction.
3. As a Host Application author, I want to capture constructor dependencies in a factory, so that Agents with required arguments work with shared Commands.
4. As a Host Application author, I want to call custom setters in my factory, so that the library does not need to know my Agent's capabilities.
5. As a Host Application author, I want to register more than one factory, so that different configured Agent types can use the same construction mechanism.
6. As a Host Application author, I want duplicate registrations rejected, so that an accidental replacement does not silently change construction behavior.
7. As a Host Application author, I want invalid or unknown configured Agent identifiers rejected, so that configuration mistakes are diagnosable.
8. As a Host Application author, I want factory failures preserved, so that the existing Command failure handling can report the actual cause.
9. As a Host Application author, I want configuration data to remain separate from live clients and tools, so that persisted settings remain JSON-compatible.
10. As a Host Application author, I want runtime choices that must survive reconstruction recorded in Configuration, so that later Agents receive those choices.
11. As a Command author, I want the existing run method to receive one Command controls adapter and CommandArguments, so that shared dependencies are available without repetitive constructor wiring.
12. As a Command author, I want to access the current Agent, so that I can inspect its History when preparing a replacement.
13. As a Command author, I want to access AgentFactoryRegistry, so that I can construct an Agent when my operation requires one.
14. As a Command author, I want to access ConfigurationStore, so that I can read or save configuration according to my operation's rules.
15. As a Command author, I want to access SessionStore, so that I can create, list and reopen the person's Sessions.
16. As a Command author, I want useAgent to activate an already prepared Agent, so that I remain responsible for the History and configuration I selected.
17. As a Command author, I want notices, warnings, prompts, selections and leaving to retain their controls, so that presentation remains independent of my Command implementation.
18. As a Command author, I want arbitrary behavior and optional application dependencies, so that adding a Command does not require extending a central catalog of operations.
19. As a Host Application author, I want to mount already constructed Commands and kits, so that the dispatcher never guesses how to instantiate a Command.
20. As a person using Clear, I want a new Session with the current configured Agent settings, so that clearing the conversation does not reset the model or enabled capabilities.
21. As a person using Clear, I want my previous Session preserved, so that I can resume it later.
22. As a person using Resume, I want to choose from saved Sessions when I omit the key, so that I do not need to remember identifiers.
23. As a person using Resume, I want my chosen Session reread when I submit its key, so that an outdated selection cannot bypass current availability checks.
24. As a person using Resume, I want the Agent constructed from the latest saved settings, so that settings changed since the selection request are respected.
25. As a person using Resume, I want a missing Session reported without replacing the active Agent, so that a failed lookup does not interrupt my conversation.
26. As a person using a reconstruction Command, I want a constructor or History-assignment failure to leave the previous Agent active, so that the interaction remains usable.
27. As a backend author, I want to execute the same Session Commands without a terminal, so that I reuse the implementation while choosing my own output format.
28. As an adapter author, I want admission, completion and technical execution outcomes preserved, so that the registry change does not redesign the dispatch protocol.
29. As a person reopening an application, I want saved configuration to remain the source for construction, so that choices survive process restarts when persistent Storage is supplied.
30. As a maintainer, I want examples and documentation to use the same contract, so that consumers can migrate both terminal and backend integrations consistently.
31. As a Host Application author, I want to supply AgentFactoryRegistry once when configuring the TUI, so that I do not wire it into every Command constructor.
32. As a Host Application author, I want to supply ConfigurationStore once, so that all Commands access the same saved user settings.
33. As a Host Application author, I want to retain control of the initial Agent and History, so that starting the TUI does not silently select a saved Session.
34. As a Host Application author, I want to construct the initial Agent with the same registered factory used later, so that startup and reconstruction apply the same settings.
35. As a Host Application author, I want Commands and kits to remain opt-in, so that supplying configuration modules does not mount unwanted Commands.
36. As a person selecting a model, I want a list when I omit the identifier, so that I can choose from the application's available models.
37. As a person selecting a model, I want an invalid identifier rejected, so that the active Agent and saved settings remain usable.
38. As a person selecting a model, I want the new Agent to retain the current History, so that changing the model does not start a new conversation.
39. As a person selecting a model, I want the choice saved for subsequent Clear, Resume and application startup, so that those operations use the chosen model.
40. As a person using the TUI during a Turn, I want the existing Command admission rules preserved, so that this migration does not introduce concurrent Agent replacement.
41. As a person submitting messages, I want later Turns to use the activated Agent, so that the displayed model choice matches the Agent that answers.
42. As a person using Input history, I want navigation and persistence unchanged, so that the configuration feature does not alter input recall.
43. As a Host Application author, I want plain conversations without reconstruction Commands to remain possible, so that registry configuration is required only when it is used.
44. As a maintainer, I want a terminal-level regression covering model change, Clear and Resume, so that tests demonstrate the original problem is fixed.
45. As a consumer maintaining both packages, I want coordinated documentation and dependency updates, so that incompatible old and new adapter contracts are not accidentally combined.

## Implementation Decisions

### neuron-interaction: shared Command contract

- Rename CommandAdapterInterface to CommandControlsAdapterInterface throughout the shared public Command protocol, implementations, examples, type annotations and tests in both repositories. The canonical supported contract after migration uses the new name; document the source compatibility change rather than maintaining two independently evolving contracts.
- CommandInterface retains name, describe and run. The run method accepts CommandControlsAdapterInterface and CommandArguments and returns void. No common constructor is required.
- Retain agent, useAgent, commands, sessionStore, say, warn, promptAgent, requestSelection, stop, admit and afterExecution with their existing responsibilities and output generic.
- Add agentFactoryRegistry returning AgentFactoryRegistry and configurationStore returning ConfigurationStore. Commands access the supplied modules through these methods. Do not add a configuration accessor that obscures which saved document is being read.
- Remove newAgent and the assumption that an arbitrary current Agent supports argument-free reconstruction. Do not replace it with implicit cloning, reflection, a default class factory or copying arbitrary instance properties.
- Retain useAgent as activation of a caller-prepared Agent with its own History. It does not select a factory, read configuration or choose a Session.
- Do not add controls named after individual Commands, a shared CommandControlsInterface, an Interaction object, an ActiveAgent object, a replaceAgent operation, or a service locator accepting arbitrary dependency names.
- Commands continues to hold mounted instances, including kit members, in order. The first matching name wins. Neither dispatch nor the registry constructs Commands.
- Preserve lookup, admission, guarded dispatch and completion ordering. Unknown identifiers reach completion without admission; refusal returns null without dispatch or completion; only exceptions from the Command become failed Command executions. Admission and completion exceptions propagate. Return the adapter's completion output unchanged.
- A completed Command invocation does not imply that a requested selection or Agent response has completed. Preserve presentation-neutral two-invocation selections.

### neuron-interaction: AgentFactoryRegistry

- Add a concrete shared registry with registration by a non-empty stable identifier and construction from Configuration. Factory registration happens in the Host Application, independently of Command mounting.
- The registration operation accepts a closure whose contract is Configuration to Agent. Each invocation must construct a fresh Agent without an already-started conversation. Captured dependencies, custom constructors, setters and capability decisions belong to the Host Application.
- The create operation reads the agent field from the supplied Configuration, requires a non-empty string, resolves exactly that registered factory and returns its Agent. It does not fall back to the current Agent's class or instantiate a class named by configuration.
- Reject duplicate identifiers and missing, invalid or unknown configured identifiers clearly. Preserve exceptions raised by factories; reject a factory result that is not an Agent through the declared return contract.
- Pass a detached Configuration value to the factory so a factory cannot accidentally mutate the caller's configuration object. Configuration contains validated JSON-compatible data, so this does not require cloning an Agent or application dependencies.
- The registry does not read or write ConfigurationStore, receive or assign History, create or select Sessions, activate Agents, run Turns, or present output.
- The general configuration uses an agent identifier plus application-owned fields such as model or searchEnabled. The library understands only the factory selector; the factory validates and interprets its other settings.

### neuron-interaction: Configuration and Session Commands

- Reuse Configuration and ConfigurationStore without introducing a new persistence format. Their existing read, create, set and save behavior remains intact.
- The shared Clear and Resume flow reads the general document under the key global. This is the convention of these flows and examples, not a restriction on ConfigurationStore's ability to hold arbitrary keys. The Host Application creates that document with explicit defaults and a registered agent identifier before using reconstruction Commands.
- A missing general configuration is a clear Command failure. Do not synthesize settings by inspecting the active Agent or silently switch to default settings. Commands that do not reconstruct an Agent continue to work without a configured factory.
- Clear reads the saved configuration and constructs the new Agent before creating its new Session. It then assigns that Session as History and calls useAgent. Preserve the previous Session and do not resave unchanged configuration.
- Resume without arguments retains its existing Session list, labels, descriptions and SelectionRequest behavior. It does not construct an Agent merely to show a selection. Empty collections retain their existing notice or warning behavior.
- Resume with a key reads the Session through the supplied user-scoped SessionStore. A missing Session produces the existing warning and no Agent replacement. A valid Session is paired with the latest saved general Configuration, constructed through the registry, assigned to the fresh Agent and activated with useAgent.
- Resume does not restore a model or configuration snapshot from the historical Session. The accepted behavior uses the current saved general configuration, leaving application-specific Agent/Session compatibility policies to application Commands.
- A deferred selection uses the currently supplied registry and stores when its follow-up invocation runs, not a captured old Configuration object.
- History assignment remains explicit in the Command and happens only on the newly constructed Agent. Agent identity constraints are respected without changing the existing Session identity behavior.
- Factory or History-assignment failure occurs before useAgent and leaves the previous Agent active. Do not claim general rollback: a created Session can remain after a later failure, and application factories must avoid mutating the previous Agent or its History.
- Durable changes to model, tools or other capabilities must be represented in Configuration and interpreted by the factory. Mutating an Agent directly does not make those changes reproducible automatically.

### neuron-interaction: backend integration

- Update the backend example to receive and expose AgentFactoryRegistry and ConfigurationStore in addition to its existing dependencies. Reuse the same shared Clear and Resume implementations as the TUI.
- Preserve backend-specific output and Host Application ownership of prompt execution, authentication, persistence scope and request concurrency. Do not prescribe a new HTTP response schema or a persistent backend Agent owner.
- Update examples to register factories, initialize or read global configuration, construct the initial Agent consistently, and provide the registry and stores to the adapter. Demonstrate an Agent with required dependencies and a setter-driven option.
- Coordinate the public contract migration with Neuron TUI. Keep the Command controls, two-step selections and technical execution semantics documented in the existing ADRs; the TUI documentation work below updates their interface name and construction description.

### neuron-tui: public composition and dependency ownership

- Integrate the Neuron Interaction contract defined above: CommandControlsAdapterInterface, AgentFactoryRegistry, ConfigurationStore and the registry-based shared Clear and Resume Commands.
- Extend Tui construction and its make convenience entry point with Host Application supplied AgentFactoryRegistry and ConfigurationStore. Preserve the existing initial Agent, terminal, Commands, SessionStore and Input history inputs and avoid reordering existing positional parameters.
- Retain construction with an explicitly supplied initial Agent. The Host Application is responsible for constructing it consistently with the saved general Configuration and registered factory. The TUI must not infer configuration from that instance or replace its History on startup.
- If registry or ConfigurationStore is omitted, supply stable empty/in-memory defaults for the lifetime of the TUI, consistent with existing optional module composition. Do not populate them by inspecting the initial Agent, invent an agent identifier, or register an argument-free factory automatically. Commands that require missing configuration fail clearly through the ordinary dispatch path; unrelated Commands and conversations remain usable.
- When persistent settings are required, the Host Application supplies ConfigurationStore backed by its chosen Storage and user scope. It supplies consistently scoped SessionStore separately; do not infer an owner from a Session or alter ownership rules.
- Supply the same registry and ConfigurationStore instances to all adapters created during one TUI run, including follow-up invocations after Picker selections. Creating a fresh adapter for an invocation must not create fresh registry contents or lose configuration state.
- Keep all Command mounting explicit. Commands still receives already constructed instances; no new per-Command constructor dependencies are required by the shared contract.

### neuron-tui: adapter and runtime integration

- Rename the implemented shared contract to CommandControlsAdapterInterface and migrate Command implementations, test fixtures, example imports and annotations accordingly.
- Expose agentFactoryRegistry and configurationStore from TuiAdapter. Retain agent, useAgent, commands, sessionStore, notices, warnings, prompts, selections, leaving, admission and completion.
- Remove newAgent and the current class make fallback. Do not introduce Interaction, ActiveAgent or replaceAgent, and do not add methods for individual Command use cases.
- ConversationRuntime remains the owner of the current answering Agent. agent returns that live instance; useAgent activates the supplied prepared Agent without looking up a factory, changing its configuration or assigning another History.
- Present the supplied Agent's History when a different conversation is activated. Preserve the documented distinction between Agent replacement and History replacement: an unchanged History should not unnecessarily reset existing notices or conversation presentation.
- Preserve the existing Commands::run dispatch pipeline. ConversationInput continues to provide the adapter; it does not instantiate individual Commands or take over technical exception classification.
- Preserve the current admission policy: only the existing permitted concurrent Commands can run while a Turn is occupied, including accepted/queued-start states. Do not expand the set of concurrent Commands or redesign scheduling in this feature.
- A deferred SelectionRequest continues to be presented after its invocation returns. Its chosen value is passed unchanged to a later invocation through an adapter with live runtime and the same configured modules.
- The next eligible Turn reads the current runtime Agent. A Turn already in progress retains the Agent with which it started. This feature must not bypass admission by calling registry-based replacements from a concurrent Command.
- Preserve technical completion and failure handling. Preparation failure leaves the old Agent active; a rendering failure after activation does not imply that activation has been rolled back.

### neuron-tui: demo bootstrap and ModelCommand

- Register the demo Agent factory in the Host Application. The factory receives Configuration, validates and interprets the demo's fields, constructs the Agent and applies the selected model and other supported options. Do not place demo-specific model/provider logic in the registry.
- Initialize the general global Configuration only when it is missing, with an explicit registered agent identifier and application defaults. Existing saved values must not be overwritten on startup.
- Construct the initial demo Agent through that registry and Configuration before assigning the explicitly chosen startup Session. Provide the same registry, ConfigurationStore and SessionStore to the TUI.
- ModelCommand remains an application Command with the common run method receiving CommandControlsAdapterInterface and CommandArguments. It obtains stores, the registry and current Agent through the adapter rather than requiring them in every Command constructor.
- Preserve the demo's model selection interaction. Without arguments, request selection using its application-owned available model list. With an identifier, validate membership and reject invalid choices before persistence or activation.
- Read a fresh general Configuration, modify its model field while preserving agent and unrelated fields, construct an Agent through AgentFactoryRegistry, and assign the current Agent's History to that fresh Agent.
- Save the modified Configuration after successful preparation and before calling useAgent, then report the change. This preserves the current demo's behavior of activating the prepared model for subsequent Turns; it does not retrofit a delayed web-only preference workflow.
- If validation, construction or History assignment fails, do not save or activate the candidate. If save throws, do not activate it. Do not promise rollback for a Storage implementation that writes before throwing, or atomicity between persistence and in-memory activation.
- Clear and Resume then read that saved model and other settings through the same general Configuration. Neither Command restores an older model from a Session or discards unrelated settings.
- Keep ModelCommand application-specific. Do not add a universal built-in model catalog or require all Agent factories to support the same settings.

### Both repositories: documentation and migration

- Update usage examples and package documentation to show registry/configuration bootstrap and the canonical CommandControlsAdapterInterface name.
- Update the domain glossary to include the registry's role and ConfigurationStore among Command controls without redefining Commands as a closed set of operations.
- Update ADR-0006 and ADR-0007 to name the new interface and describe explicit registry-based preparation by Session Commands. Preserve their decisions about Commands returning void, two-step selections, admission/completion and technical outcomes.
- Align dependency requirements and local example composition with the shared contract revision. Do not perform unrelated dependency upgrades or rewrite the in-progress Neuron 4 migration.
- Document the migration from newAgent and CommandAdapterInterface, the requirement to register a reproducible construction, and the need to save runtime choices that should survive reconstruction.

### Coordinated implementation and completion

- Treat this as one feature spanning two checkouts, not two independently accepted deliverables. The Host Application construction contract, registry semantics and Command controls have one definition in this specification.
- First implement AgentFactoryRegistry, the renamed adapter contract, shared Session Commands and the backend example in neuron-interaction, preserving the dispatcher semantics.
- Then integrate that same shared revision in neuron-tui, including public composition, deferred selections, demo bootstrap, ModelCommand and documentation. Work in the two existing local checkouts; a second planning or approval cycle is not required merely because the repository changes.
- Finally run both repositories' relevant suites and static analysis, the backend examples and the TUI demo checks against the same integrated dependency revision. Prove the complete model-change, Clear and Resume behavior before considering the feature implemented.
- Keep dependency changes limited to consuming the new shared contract. Passing the shared package tests alone or the TUI tests against an older contract does not satisfy completion.

## Testing Decisions

Use the existing public execution seams at the highest practical level: Commands::run for shared behavior and Tui::run for the terminal integration. Use focused direct registry tests only for behavior such as registration errors that cannot naturally be exercised through Command dispatch. These are complementary parts of one verification plan.

### Shared behavior in neuron-interaction

- Primary shared seam: Commands::run with concrete shared Commands, the backend example adapter, real AgentFactoryRegistry, and existing in-memory or temporary file-backed stores.
- Assert observable outcomes: active Agent identity and behavior, assigned History and thread identity, saved configuration, Session availability, selection contents, notices, warnings and technical adapter output. Avoid tests that only mirror the internal call sequence or private properties.
- Use deterministic Neuron provider fixtures for actual Agent Turns. Prove that an Agent which has already answered can be replaced, answer on a different Session and resume the original Session without losing the selected model or setter-driven option.
- Through the shared dispatch seam, cover required constructor dependencies, successive factory-created instances, latest saved configuration, Clear preserving prior Sessions, Resume by key, and a selection whose follow-up sees updated configuration.
- Verify missing Session, missing general configuration, unknown configured Agent, invalid configuration and throwing factory behavior. Failed preparation must preserve the previously active Agent and its usable conversation; no test should assert an unsupported rollback of arbitrary storage or factory side effects.
- Add focused registry-interface tests for registration errors and factory selection that cannot naturally be asserted through Command dispatch. Prefer the smallest public seam; do not introduce a registry interface merely for mocking.
- Reuse prior art from CommandsTest, CommandAdapterTest, ResumeCommandTest, SelectionTest, BackendExampleTest and ConfigurationStoreTest. Adapt or replace tests asserting argument-free make reconstruction, since that behavior is intentionally removed.
- Preserve existing dispatch-contract tests, Command kit composition, first-match name resolution, unchanged arguments, and backend completion output.
- Run the repository's PHPUnit and PHPStan checks and validate relevant examples after implementation. Use local deterministic dependencies; no external model calls are required.

### Terminal behavior in neuron-tui

- Primary terminal integration seam: public Tui composition and Tui::run with VirtualTerminal, real shared Commands and stores, and deterministic Neuron FakeAIProvider fixtures.
- Assert visible History, selection behavior, warnings/notices, stored settings and which configured Agent answers the next Turn. Do not rely solely on checking a model property or counting factory invocations.
- Use a test Agent with a required constructor dependency and a setter-controlled capability. Run an initial Turn, select another model, Clear into a new Session, run another Turn and Resume the earlier Session. Verify fresh Agent instances, appropriate thread/History identity, preserved selected configuration and usable subsequent responses.
- Verify registry and ConfigurationStore remain available and consistent across repeated Commands and a deferred Picker follow-up. Include configuration changed between requesting and completing a selection.
- Verify a missing general configuration, unknown factory identifier, invalid model and throwing factory produce understandable failure without replacing the existing Agent. Use actual subsequent behavior to show the interaction remains usable.
- Verify a failed model save does not activate the candidate, without assuming stronger storage rollback guarantees than the chosen fixture provides.
- Preserve tests for explicit startup History, no automatic stored-Session selection, empty default Commands, shared module identity, Input history, duplicate Command names, and admission during accepted or active Turns.
- Reuse prior art from SessionCompositionTest, TuiTest, TuiAdapterTest, AgentTurnTest and existing deterministic provider fixtures. Adapt tests that currently require argument-free make reconstruction; those assertions describe the behavior being removed.
- Keep shared dispatch/registry cases in Neuron Interaction where the same behavior is already exercised through Commands::run and the backend example. TUI tests should establish terminal integration, not duplicate every low-level shared assertion.
- Run TUI PHPUnit and PHPStan checks and the relevant demo checks against the coordinated Neuron Interaction version. Do not use paid or remote model calls to prove this feature.

### Cross-repository acceptance

- Use the same factory/configuration contract for initial construction, a model change, Clear and Resume. The model change preserves current History; Clear creates another Session; Resume returns to the selected prior Session with the latest saved configuration.
- Verify actual subsequent responses from deterministic provider-backed Agents, required constructor dependencies and setter-driven behavior, not merely successful construction or a stored model string.
- Deliver both repository changes together as one accepted feature. The reference in neuron-tui points here and has no separate implementation requirements or acceptance state.

## Out of Scope

- Migrating AgentDeck, implementing a new browser frontend or prescribing a new HTTP transport. The existing backend example in neuron-interaction is in scope.
- Adding an interactive Agent-switch Command or universal Agent/History compatibility rules. The registry can select different factories; this feature does not add a new product flow for switching Agent types.
- Replacing the current Command invocation protocol with callable registration, local-only Commands, declarative proposals or domain result interpreters.
- Introducing Interaction, ActiveAgent, replaceAgent, Command-specific control methods, a universal model/provider abstraction, or required dependency injection into every Command constructor.
- Recovering arbitrary constructor/setter history from a live Agent, cloning Agents, or serializing application dependencies.
- Changing Neuron AI itself or unrelated Turn streaming, History projection, rendering, Input history or human-in-the-loop work already in progress. Integration regressions caused by this feature remain in scope.
- Distributed locking, cross-store transactions, crash recovery, a scheduling redesign, or changes to existing storage ownership and authorization policies.
- Creating implementation tickets, implementing production code, committing, releasing or deploying as part of publishing this specification. Those are subsequent activities for the same coordinated feature.

## Further Notes

This is the single authoritative specification for both neuron-interaction and neuron-tui. The file in neuron-tui is a navigation reference, not a second specification or a separate task to implement independently.

The final accepted design retains the Command controls adapter and shared Session Commands. CommandControlsAdapterInterface exposes agent, agentFactoryRegistry, configurationStore and sessionStore and retains useAgent and presentation controls. Earlier sketches involving Interaction, ActiveAgent, replaceAgent, local-only Commands or different invocation styles are not implementation requirements.

Both workspaces contain uncommitted Neuron 4 migration changes. Preserve unrelated work and account for those changes rather than resetting the checkouts, performing broad dependency updates or treating the existing passing tests as proof of configuration preservation.

The former separate draft documents are superseded by this combined specification. Future changes to requirements and acceptance criteria belong here only.
