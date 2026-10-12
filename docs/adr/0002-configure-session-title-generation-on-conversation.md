# Configure automatic Session title generation on Conversation

Automatic title generation is enabled by configuring a SessionTitleGenerator on
Conversation through setSessionTitleGenerator(), just as input history is enabled
through setInputHistory(). Conversation knows when a message turn completes and
whether interruption was requested; configuring it once also preserves the
behavior when the selected Session changes, without configuring each created or
reopened Session.

SessionTitleGenerator receives the provider at construction and the Session when
generate() is called. It reads saved messages, preserves existing titles, and
persists the generated title and attempt count through Session. Session retains
responsibility for saved data without holding the generator configuration.

Generation runs synchronously after completed turns and adds latency before the
turn returns. Failures must not invalidate the completed response. The Host
Application continues to coordinate concurrent operations on the same Session.
