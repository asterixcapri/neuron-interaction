# Neuron Interaction examples

Install this separate Composer project and configure its `.env`:

```bash
cd examples
composer install
```

Set `OPENAI_API_KEY` in `.env`. If the file is absent, copy `.env.example` and
fill in the key. Executable examples live in `bin/`; shared Host configuration
and presentation live in `src/`.

Examples 01 and 02 use a plain `Agent`; the other Agent examples use `DemoAgent`.
They configure their provider through `AIProviderFactory` and use the real
OpenAI model `openai:gpt-5.4-nano`. To change the model, edit the identifier in the
script. Responses are printed in streaming. Input history and preferences
are independent modules and do not require an Agent or credentials.

| Order | Run | What to observe |
| --- | --- | --- |
| 01 | `composer input` | Submit a normal message and print the Agent's response in streaming. |
| 02 | `composer sessions` | Switch between two Sessions, view their messages and continue with each one's context. |
| 03 | `composer commands` | Shared Commands list themselves, clear a conversation and resume it; a custom Command prompts the Agent. |
| 04 | `composer selection` | Build a custom `/language` Command and choose the Agent's response language interactively. |
| 05 | `composer interruption` | Stop a streamed answer, inspect the saved partial message and start a new turn. |
| 06 | `composer processors` | Expand `@trip.txt` for the Agent and show the compact original message when displaying History. |
| 07 | `composer input-history` | Recall original inputs, including Command syntax, and restore the current draft. |
| 08 | `composer preferences -- Italian` | Save a user preference; run `composer preferences` again to read it in another process. |
| 09 | `composer portable-selection` | Present labeled choices and submit an opaque value without an AI provider. |
| 10 | `composer echo` | Run a local `/echo` Command and handle `/exit` without an AI provider. |

## 01 — Input and response

The script configures an Agent with an AI provider, explicitly creates a Session
in memory, passes it to Conversation and submits a normal greeting through
`Conversation::sendInput()`. Consuming the returned stream executes the Agent;
the script prints each `TextChunk` as it arrives. This example requires
`OPENAI_API_KEY` and keeps no state between runs.

## 02 — Multiple Sessions

The first Session records Lisbon as your destination; the second records Kyoto.
The script lists saved Sessions, returns to each one, prints its messages and asks
“What is my destination?” The respective answers should mention Lisbon and Kyoto.

Neuron AI executes the Agent. Interaction provides the user's collection of
Sessions, listing, titles, persistence and switching the active context.
Files live in `.storage/multiple-sessions/`; every run creates two more Sessions.
The script shows listing, retrieval by key, switching and saved messages directly.
Its streaming presentation helper is defined in the same file.

## 03 — Commands

This interactive terminal example reads a message or Command on each turn.
Try a normal message, `/help`, `/explain PHP generators`, `/clear`, `/resume`
and `/exit`. The script builds its registry with `addCommand()` and supplies it
through `Conversation::setCommands()`.

The main loop simulates a consumer: it reads input, calls `sendInput()`
and displays events as the stream yields them. A backend can forward those
events to its client, for example via SSE. The example handles TextChunks,
Notifications, SessionChanged, AgentChanged, SelectionRequest and ExitRequest.
For SelectionRequest, the client reads a numbered choice and creates a
CommandInput. It sends that input as a new backend request on its next
iteration, after the preceding stream has been fully consumed. Enter cancels;
an invalid number cancels that choice. `/resume` also accepts a key directly.
`/exit` or end of input closes the client loop. The registered Commands do not
emit AgentChanged; its branch illustrates where the client would refresh its UI.
To provide input from a pipe, run `php bin/03-commands.php` directly.

`src/ExplainCommand.php` registers a UserMessage through `promptAgent()`.
Conversation executes that prompt and streams its response in the same turn.
FileStorage keeps previous conversations under `.storage/commands/`; each run
creates one new Session, and `/clear` creates another without deleting history.

## 04 — Selection

This interactive, self-contained example defines a custom `/language` Command. Without an
argument it emits SelectionRequest with three response languages. With a selected
value it replaces the demo Agent's instructions and emits a Notification.
Try `/language`, `/language Italian`, then a normal message to see the selected
language in use. `/exit` or end of input closes the example.

The consumer displays the options, reads a number and sends a new CommandInput
using the event's command and the chosen option's value. Enter cancels and returns
to the normal prompt. The choice is sent on the next loop iteration, after the
current stream is fully consumed. Conversation has no pending selection state.
To supply input from a pipe, invoke `php bin/04-selection.php` directly.

## 05 — Interruption

The script requests a long answer and simulates pressing Stop after its first
text chunk. Conversation and Neuron's StoppableHttpClient share a StopSignal.
The script keeps consuming the stream, prints `Response stopped: yes`, displays
the saved partial response and executes a new turn normally.

An application connects `requestInterruption()` to its stop button or input
handler. This interrupts the HTTP response, not the execution of local tools.

## 06 — Processors

The user submits a reference to `fixtures/trip.txt`. The processor adds its
contents before execution; the real response should summarize the Lisbon trip,
including its dates, budget or interests. The script then prints the saved
expanded message and its compact `forDisplay()` projection.

`FileReferenceProcessor` is adapted from the Neuron TUI example. It preserves
original input and already expanded references. For this example, the Agent's
tools are cleared so the file context is supplied by the processor.

## 07 — Input history

Original submissions are stored independently of Session messages: a question
and `/help`. A fresh InputHistory reads them from the same storage. The script
simulates Up twice and Down twice; the final value is `My unfinished question`.
Your client supplies the keyboard or button handling.

## 08 — Preferences

```bash
composer preferences -- Italian
composer preferences
```

The first invocation saves a language choice. The second reads Italian without
receiving that choice again. A fresh Store for another user still returns its
English fallback. To change the choice, run `composer preferences -- English`.
The Host decides how to apply a preference, for example to response language or
model selection; ConfigurationStore handles its persistence and user scope.

## Dependencies, storage and validation

`examples/composer.json` owns all example dependencies, including Dotenv and Amp.
The path repository symlinks Neuron Interaction to the parent checkout. The
library's Composer installation does not install these example dependencies.

File-backed examples store their state under `.storage/`. SessionStore and
ConfigurationStore use `demo-user`; an application supplies its authenticated
user's identity. For InputHistory, the Host supplies Storage scoped to that user. Sessions, input
history and preferences have separate example directories. Selection, stop and
processor examples use memory so each run begins with only its own data.

Run `composer stan` here to analyse the scripts and shared example code. The
repository's `composer stan` analyses the library and its tests separately.
The implementation order and decisions are recorded in the
[approved plan](../.scratch/rebuild-examples/spec.md).

## 09 — Portable selection without a provider

Run `composer portable-selection` to present labeled choices and submit their
opaque value through CommandInput. Unlike the Session picker in example04, this
example needs no API key. The Command validates and persists the chosen preference;
the host has no adapter or hidden continuation.

## 10 — Echo and host exit without a provider

The script submits `/echo` through `Conversation::sendInput()` and prints its
Notification. `/exit` emits an `ExitRequest`; the terminal host stops its own input
loop. Conversation remains usable, so a web host can ignore the same request.
The Agent has no provider because neither Command prompts it. This example needs
no API key. An exit request does not stop an Agent response:
`requestInterruption()` is the separate operation used in example 05.
