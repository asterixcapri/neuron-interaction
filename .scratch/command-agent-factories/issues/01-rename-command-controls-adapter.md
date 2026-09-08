# 01: Preserve Command execution through the renamed controls adapter

**What to build:** Host Applications and existing Commands use the canonical CommandControlsAdapterInterface name in both neuron-interaction and neuron-tui without changing how input is admitted, executed or presented. This is a bounded mechanical prefactoring step across the two checkouts, completed and verified together before registry behavior is added.

**Blocked by:** None (can start immediately).

**Status:** resolved

**Parent:** [Coordinated specification](../spec.md)

- [x] CommandInterface retains name, describe and a void run method taking the renamed adapter and CommandArguments; Commands remain already constructed instances with arbitrary constructors.
- [x] Shared Commands, kits, concrete and test adapters, application examples and TUI callers consistently use CommandControlsAdapterInterface. No supported caller in either repository remains on the old type when this ticket is complete.
- [x] Perform the small, mechanical rename as one coordinated change across both local checkouts. Update the shared declaration before consumers and verify the integrated revision; do not treat a half-renamed package pair as a completed ticket or publish intermediate incompatible packages.
- [x] Existing control methods and behavior are preserved in this prefactor, including newAgent temporarily. Registry access and removal of argument-free construction belong to the subsequent functional tickets.
- [x] Dispatch preserves first matching name, unchanged arguments, admission before execution, null refusal, unknown-command completion without admission, captured Command exceptions and propagation of admission/completion exceptions.
- [x] afterExecution still receives the technical Command execution and Commands::run returns the adapter's output unchanged. A completed invocation may still have a pending selection or Agent response.
- [x] Backend example output, TUI errors/notices, two-invocation selection behavior and current concurrent-Command policy remain unchanged.
- [x] Update interface-name references in usage documentation and the relevant ADRs without changing their decisions about invocation, selection or technical outcomes.
- [x] Validate existing shared dispatch tests and terminal integration tests, with PHPUnit and PHPStan passing against the same shared dependency revision.
- [x] Preserve unrelated Neuron 4 migration work. Do not add Interaction, ActiveAgent, replaceAgent, callable dispatch or control methods named after individual Commands.

## Implementation

Renamed the shared interface and all supported consumers in the coordinated
Neuron Interaction and Neuron TUI worktrees, including usage documentation
and ADR references. The dispatcher and control behavior, including `newAgent`,
are unchanged.

Validation against the same local shared dependency revision:

- Neuron Interaction: PHPUnit passes (157 tests, 595 assertions); PHPStan passes.
- Neuron TUI: PHPUnit passes (221 tests, 974 assertions); PHPStan passes.
- All six shared backend examples complete successfully.
- No pre-existing check failures were observed.
