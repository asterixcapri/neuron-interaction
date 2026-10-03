# Neuron Interaction examples

Install this separate Composer project and configure its `.env`:

```bash
cd examples
composer install
```

Set `OPENAI_API_KEY` in `.env`. If the file is absent, copy `.env.example` and
fill in the key. Executable examples live in `bin/`; shared Host configuration
and presentation live in `src/`.

Examples that execute an Agent use `DemoAgent`, `AIProviderFactory` and the real
OpenAI model `openai:gpt-5.4-nano`. To change the model, edit the identifier in the
script. Every response is printed in streaming. Input history and preferences
are independent modules and do not require an Agent or credentials.

| Order | Run | What to observe |
| --- | --- | --- |
| 00 | `php bin/00-input.php` | Submit a Command, then let the terminal host handle an exit request without credentials. |
| 01 | `composer sessions` | Switch between two Sessions, view their messages and continue with each one's context. |
| 02 | `composer commands` | Shared Commands list themselves, clear a conversation and resume it; a custom Command prompts the Agent. |
| 03 | `composer selection` | Choose a Session from the options returned by `/resume`, then continue the selected conversation. |
| 04 | `composer interruption` | Stop a streamed answer, inspect the saved partial message and start a new turn. |
| 05 | `composer processors` | Expand `@trip.txt` for the Agent and show the compact original message when displaying History. |
| 06 | `composer input-history` | Recall original inputs, including Command syntax, and restore the current draft. |
| 07 | `composer preferences -- Italian` | Save a user preference; run `composer preferences` again to read it in another process. |

## 00 — Input and host exit

The script submits `/echo` through `Conversation::submitInput()` and prints its
Notification. `/exit` emits an `ExitRequest`; the terminal host stops its own input
loop. Conversation remains usable, so a web host can ignore the same request.
An exit request does not stop an Agent response: `requestInterruption()` is the
separate operation used for response stopping in example 04.

## 01 — Multiple Sessions

The first Session records Lisbon as your destination; the second records Kyoto.
The script lists saved Sessions, returns to each one, prints its messages and asks
“What is my destination?” The respective answers should mention Lisbon and Kyoto.

Neuron AI executes the Agent. Interaction provides the user's collection of
Sessions, listing, titles, persistence and switching the active context.
Files live in `.storage/multiple-sessions/`; every run creates two more Sessions.

## 02 — Commands

A real exchange introduces Ada. `/help` prints the mounted Commands, and the
custom `/explain` Command asks the Agent about PHP generators. `/clear` selects
an empty Session; `/resume` with the original key restores the saved exchange.
The final question asks the Agent for the name Ada.

`src/ExplainCommand.php` defines behaviour through the neutral Command controls.
`src/TerminalCommandAdapter.php` prints notices and streams Command-generated
prompts through the Host's Conversation. The same Commands can be mounted by
another Adapter. No callback or input queue is introduced.

## 03 — Selection

Two exchanges prepare Lisbon and Kyoto Sessions. `/resume` without arguments
requests a Selection, and the Adapter displays numbered options. Enter a number
to choose, or press Enter to cancel. After selection, the script prints the saved
messages and asks for the destination in that context.

The Host reads the choice and invokes `selection->command` again with the chosen
`option->value`. The Selection does not depend on terminal input: another Host
could present it in a web UI and submit the same value in its next request.
To supply input from a pipe, invoke `php bin/03-selection.php` directly.

## 04 — Interruption

The script requests a long answer and simulates pressing Stop after its first
text chunk. Conversation and Neuron's StoppableHttpClient share a StopSignal.
The script keeps consuming the stream, prints `Response stopped: yes`, displays
the saved partial response and executes a new turn normally.

An application connects `requestInterruption()` to its stop button or input
handler. This interrupts the HTTP response, not the execution of local tools.

## 05 — Processors

The user submits a reference to `fixtures/trip.txt`. The processor adds its
contents before execution; the real response should summarize the Lisbon trip,
including its dates, budget or interests. The script then prints the saved
expanded message and its compact `forDisplay()` projection.

`FileReferenceProcessor` is adapted from the Neuron TUI example. It preserves
original input and already expanded references. For this example, the Agent's
tools are cleared so the file context is supplied by the processor.

## 06 — Input history

Original submissions are stored independently of Session messages: a question
and `/help`. A fresh InputHistory reads them from the same storage. The script
simulates Up twice and Down twice; the final value is `My unfinished question`.
Your client supplies the keyboard or button handling.

## 07 — Preferences

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
