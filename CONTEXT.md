# Neuron Interaction

Shared language for applications in which a person interacts with a Neuron AI Agent.

## Language

**Conversation**:
The active interaction with an Agent in a selected Session, including messages,
Commands and choices presented to the person.
_Avoid_: Conversation runtime

**Command**:
A named operation requested by the person that can change the interaction state,
provide feedback, ask for a choice or request a prompt to the Agent.

**SelectionRequest**:
A request from a Command to present labeled choices whose values identify its
next step. The Host Application presents the choices; the Command interprets the answer.

**ExitRequest**:
A request from a Command for the Host Application to leave its interface. The
Host Application may fulfill or ignore it; the Conversation is not terminated.

**Session**:
A saved conversation belonging to a user, with an identity, messages, title and
application metadata.

**SessionSummary**:
An immutable snapshot of the information used to recognize a Session in a listing:
its identity, title, last use and history size.

**Notification**:
Feedback intended for the person, with text and a severity level. It is presented
by the Host Application and is not a prompt to the Agent.
