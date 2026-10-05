# Neuron Interaction examples

Install this separate Composer project and configure its `.env`:

```bash
cd examples
composer install
```

Set `OPENAI_API_KEY` in `.env`. If the file is absent, copy `.env.example` and
fill in the key. Executable examples live in `bin/`; shared Host configuration
and presentation live in `src/`.

All Agent examples use `new Agent()`.
They configure their provider through `AIProviderFactory` and use the real
OpenAI model `openai:gpt-5.4-nano`. To change the model, edit the identifier in the
script. Responses are printed in streaming. Input history and preferences
are independent modules and do not require an Agent or credentials.

| Order | Run | What to observe |
| --- | --- | --- |
| 01 | `composer input` | Submit a normal message and print the Agent's response in streaming. |
| 02 | `composer sessions` | Switch between two Sessions, view their messages and continue with each one's context. |
| 03 | `composer commands` | Use `/help`, `/clear` and `/exit` in an interactive conversation. |
| 04 | `composer selection` | Choose a previous Session with SelectionRequest and CommandInput. |
| 05 | `composer custom-selection` | Choose a model and change the Agent through a custom Command. |
| 06 | `composer interruption` | Stop a streamed answer after a random number of text chunks. |
| 07 | `composer processors` | Expand `@README.md` into a `<file>` tag for the Agent and restore the reference when displaying History. |
| 08 | `composer input-history` | Persist original inputs, including Command syntax, and list them later. |
| 09 | `composer configuration` | Save a language preference and apply it to an Agent response. |
| 10 | `composer custom-command` | Change the response language through a custom Command and persist the preference. |

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
The script shows listing, retrieval by key and switching directly, then displays
the saved messages through `Conversation::getDisplayMessages()`.
Its streaming presentation helper is defined in the same file.

## 03 — Commands

This interactive terminal example reads a message or Command on each turn.
Try a normal message, `/help`, `/clear`
and `/exit`. The script passes each Command to the `Commands` constructor and supplies the registry
through `Conversation::setCommands()`.

The main loop simulates a consumer: it reads input, calls `sendInput()`
and displays events as the stream yields them. A backend can forward those
events to its client, for example via SSE. The example handles TextChunks,
Notifications, SessionChanged, AgentChanged and ExitRequest.
`/exit` or end of input closes the client loop. The registered Commands do not
emit AgentChanged; its branch illustrates where the client would refresh its UI.
To provide input from a pipe, run `php bin/03-commands.php` directly.

FileStorage keeps previous conversations under `.storage/commands/`; each run
creates one new Session, and `/clear` creates another without deleting history.

## 04 — Selection

This interactive example registers `/resume`, `/clear` and `/exit`. Send a
normal message, use `/clear`, then choose the earlier Session with `/resume`.
Sessions remain in memory for this run.

The consumer displays each SelectionRequest, reads an option number and sends a
CommandInput on the next loop iteration, after consuming the previous stream.
Enter cancels the choice. SessionChanged shows where a client refreshes its
conversation using `getDisplayMessages()`. To provide input from a pipe, run `php bin/04-selection.php` directly.

## 05 — Custom selection

Run `composer custom-selection` or `php bin/05-custom-selection.php`. This separate, self-contained
example defines `/model`, offers two OpenAI models and changes the Agent after a
choice. It also shows the AgentChanged event. Try `/model`, select a model, then
send a normal message.

## 06 — Interruption

The script requests a long answer and picks a random threshold of 40–50 text
chunks. It simulates pressing Stop when that many chunks have arrived.
Conversation and Neuron's StoppableHttpClient share a StopSignal. The script
keeps consuming the stream and prints whether the response stopped.

An application connects `requestInterruption()` to its stop button or input
handler. This interrupts the HTTP response, not the execution of local tools.

## 07 — Processors

The user submits `@README.md`. The processor turns it into a
`<file name="README.md">` tag containing the repository's existing README before
execution; the response should summarize the project. The script
confirms through `Conversation::getMessages()` that the saved message includes
the README, then prints all Session messages through `getDisplayMessages()`.
The user message shows the original `@README.md` reference, followed by the
Agent's response, without dumping the whole file.

`FileUserMessageProcessor` is defined in the example file. Its `tagName()`
method tells `AbstractUserMessageTagProcessor` to produce a `file` tag from `@` references.
The base class handles replacement and display; the example class resolves any
named text file inside the configured directory and returns its contents.

## 08 — Input history

Conversation automatically records original submissions through `setInputHistory()`: a question
and `/help`, independently of Session messages. The script lists the saved inputs. Navigation and draft restoration belong to
the consumer.

## 09 — Configuration

Run `composer configuration` to save Spanish for `demo-user` and apply it to an
Agent answering an English question. Another user has no saved language.
ConfigurationStore handles persistence and user scope; the Host applies the
preference through Agent instructions.

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

## 10 — Custom command

Run `composer custom-command`, submit `/language Italian`, then ask a question in
English. `LanguageCommand` saves the preference through ConfigurationStore and
updates the Agent instructions, so the next response uses Italian. Restarting
restores the saved language. The command accepts English, Italian, Spanish and
Portuguese. `/language English` changes it back; `/exit` ends
the consumer's input loop. Invalid language values produce a Notification.
